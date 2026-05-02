<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\KandangMortalityLog;
use App\Models\KandangPeriode;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KandangController extends Controller
{
    public function index(Request $request)
    {
        $query = Kandang::query()->whereIn('id_kandang', $this->accessibleKandangIds());

        if ($request->filled('name')) {
            $query->where('nama_kandang', 'like', '%'.$request->query('name').'%');
        }

        return response()->json($query->with('user:id,name')->orderBy('nama_kandang')->get()->map(function (Kandang $row) {
            $row->primary_owner_id = $row->user_id;
            $row->primary_owner_name = $row->user?->name;
            unset($row->user);

            return $row;
        }));
    }

    public function store(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $request->validate([
            'nama_kandang' => [
                'required',
                'string',
                'max:255',
                Rule::unique('kandang', 'nama_kandang')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
            ],
            'kapasitas' => ['required', 'integer', 'min:0'],
            'populasi' => ['required', 'integer', 'min:0'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        $kandang = DB::transaction(function () use ($data) {
            $kandang = Kandang::create($data + [
                'user_id' => $this->dataOwnerId(),
                'created_by' => $this->creatorId(),
            ]);

            KandangPeriode::create([
                'user_id' => $this->dataOwnerId(),
                'created_by' => $this->creatorId(),
                'id_kandang' => $kandang->id_kandang,
                'nama_periode' => 'Periode 1',
                'populasi_awal' => $data['populasi'],
                'total_kematian' => 0,
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
                'status' => empty($data['tanggal_selesai']) ? 'aktif' : 'selesai',
            ]);

            return $kandang;
        });
        ActivityLogger::log('create', 'kandang', $kandang, null, $kandang->toArray(), $request);

        return response()->json(['status' => true, 'message' => 'Berhasil menambahkan kandang!'], 201);
    }

    public function showByOwner(Request $request)
    {
        $query = Kandang::query()
            ->with(['periodes' => fn ($query) => $query->orderByRaw("status = 'aktif' desc")->orderByDesc('tanggal_mulai')->orderByDesc('id_periode')])
            ->with('user:id,name')
            ->whereIn('id_kandang', $this->accessibleKandangIds());

        if ($request->filled('owner_name') || $request->filled('name')) {
            $search = $request->query('owner_name', $request->query('name', ''));
            $query->where('nama_kandang', 'like', "%$search%");
        }

        $data = $query->orderBy('nama_kandang')
            ->get()
            ->map(function ($row) {
                $periode = $row->periodes->first();
                $row->nama_tampilan = $row->nama_kandang;
                $row->primary_owner_id = $row->user_id;
                $row->primary_owner_name = $row->user?->name;
                $row->jumlah_periode = $row->periodes->count();
                $row->id_periode = $periode?->id_periode;
                $row->nama_periode = $periode?->nama_periode ?? '-';
                $row->status_periode = $periode?->status ?? '-';
                $row->populasi = $periode?->populasi_awal ?? $row->populasi;
                $row->total_kematian = $periode?->total_kematian ?? $row->total_kematian;
                $row->ayam_sekarang = max(0, (int) $row->populasi - (int) $row->total_kematian);
                $row->tanggal_mulai = $periode?->tanggal_mulai ?? $row->tanggal_mulai;
                $row->tanggal_selesai = $periode?->tanggal_selesai;
                unset($row->periodes, $row->user);

                return $row;
            });

        return response()->json($data);
    }

    public function search(Request $request)
    {
        $name = $request->query('name', '');

        $data = Kandang::query()
            ->select('id_kandang', 'user_id', 'nama_kandang')
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->when($name !== '', fn ($query) => $query->where('nama_kandang', 'like', "%$name%"))
            ->orderBy('nama_kandang')
            ->with('user:id,name')
            ->get()
            ->map(function (Kandang $row) {
                $row->primary_owner_id = $row->user_id;
                $row->primary_owner_name = $row->user?->name;
                unset($row->user);

                return $row;
            });

        return response()->json($data);
    }

    public function update(Request $request, Kandang $kandang)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $request->validate([
            'nama_kandang' => [
                'required',
                'string',
                'max:255',
                Rule::unique('kandang', 'nama_kandang')
                    ->where(fn ($query) => $query->where('user_id', $this->dataOwnerId()))
                    ->ignore($kandang->id_kandang, 'id_kandang'),
            ],
            'kapasitas' => ['required', 'integer', 'min:0'],
            'populasi' => ['required', 'integer', 'min:0'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        if ((int) $kandang->user_id !== (int) $this->dataOwnerId()) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $kandang->toArray();
        DB::transaction(function () use ($kandang, $data) {
            $kandang->update($data);

            $periode = $this->editablePeriod($kandang);

            if ($periode) {
                $periode->update([
                    'populasi_awal' => $data['populasi'],
                    'tanggal_mulai' => $data['tanggal_mulai'],
                    'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
                    'status' => empty($data['tanggal_selesai']) ? 'aktif' : 'selesai',
                ]);
            }
        });
        ActivityLogger::log('update', 'kandang', $kandang, $before, $kandang->fresh()->toArray(), $request);

        return response()->json(['status' => true, 'message' => 'Success']);
    }

    public function updateFromRequest(Request $request)
    {
        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id_kandang'));

        return $this->update($request, $kandang);
    }

    public function setPeriode(Request $request, Kandang $kandang)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $request->validate([
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        if (! $this->canAccessKandang($kandang->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $kandang->toArray();
        $kandang->update($data);

        $periode = $this->editablePeriod($kandang);

        if ($periode) {
            $periode->update([
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
                'status' => empty($data['tanggal_selesai']) ? 'aktif' : 'selesai',
            ]);
        }
        ActivityLogger::log('update', 'kandang_periode', $kandang, $before, $kandang->fresh()->toArray(), $request);

        return response()->json(['status' => true, 'message' => 'Periode kandang disimpan']);
    }

    private function editablePeriod(Kandang $kandang): ?KandangPeriode
    {
        return $kandang->periodes()
            ->orderByRaw("status = 'aktif' desc")
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id_periode')
            ->first();
    }

    public function setPeriodeFromRequest(Request $request)
    {
        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id_kandang'));

        return $this->setPeriode($request, $kandang);
    }

    public function addMortality(Request $request, Kandang $kandang)
    {
        $data = $request->validate([
            'jumlah_kematian' => ['required', 'integer', 'min:1'],
            'tanggal' => ['nullable', 'date'],
        ]);

        if (! $this->canAccessKandang($kandang->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $periode = $this->editablePeriod($kandang);
        $before = [
            'kandang' => $kandang->toArray(),
            'periode' => $periode?->toArray(),
        ];

        $log = DB::transaction(function () use ($kandang, $periode, $data) {
            $jumlah = (int) $data['jumlah_kematian'];
            $kandang->increment('total_kematian', $jumlah);

            if ($periode) {
                $periode->increment('total_kematian', $jumlah);
            }

            return KandangMortalityLog::create([
                'user_id' => $this->dataOwnerIdForKandang($kandang->id_kandang),
                'created_by' => $this->creatorId(),
                'id_kandang' => $kandang->id_kandang,
                'id_periode' => $periode?->id_periode,
                'tanggal' => $data['tanggal'] ?? now()->toDateString(),
                'jumlah_kematian' => $jumlah,
            ]);
        });
        ActivityLogger::log('create', 'kandang_mortality', $log, $before, [
            'log' => $log->toArray(),
            'kandang' => $kandang->fresh()->toArray(),
            'periode' => $periode?->fresh()?->toArray(),
        ], $request);

        return response()->json(['status' => true, 'message' => 'Success']);
    }

    public function mortalityLogs(Request $request)
    {
        $query = KandangMortalityLog::query()
            ->with(['kandang.user:id,name', 'periode:id_periode,nama_periode', 'creator:id,name'])
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if ($request->filled('id_kandang')) {
            $query->where('id_kandang', $request->query('id_kandang'));
        }

        return response()->json([
            'status' => true,
            'data' => $query->limit(200)->get()->map(fn (KandangMortalityLog $log) => [
                'id' => $log->id,
                'id_kandang' => $log->id_kandang,
                'id_periode' => $log->id_periode,
                'nama_kandang' => $log->kandang?->nama_kandang,
                'primary_owner_id' => $log->kandang?->user_id,
                'primary_owner_name' => $log->kandang?->user?->name,
                'nama_periode' => $log->periode?->nama_periode,
                'tanggal' => $log->tanggal?->toDateString(),
                'jumlah_kematian' => $log->jumlah_kematian,
                'created_by' => $log->created_by,
                'creator_name' => $log->creator?->name,
                'created_at' => optional($log->created_at)?->format('Y-m-d H:i'),
                'can_edit' => ! $this->isFarmWorker() || (int) $log->created_by === (int) $this->creatorId(),
            ])->values(),
        ]);
    }

    public function updateMortalityLog(Request $request, KandangMortalityLog $log)
    {
        $data = $request->validate([
            'jumlah_kematian' => ['required', 'integer', 'min:1'],
            'tanggal' => ['nullable', 'date'],
        ]);

        if (! $this->canAccessKandang($log->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        if ($this->isFarmWorker() && (int) $log->created_by !== (int) $this->creatorId()) {
            return response()->json(['status' => false, 'message' => 'Farm worker hanya boleh koreksi catatan sendiri'], 403);
        }

        $kandang = Kandang::query()->findOrFail($log->id_kandang);
        $periode = $log->id_periode ? KandangPeriode::query()->find($log->id_periode) : null;
        $before = [
            'log' => $log->toArray(),
            'kandang' => $kandang->toArray(),
            'periode' => $periode?->toArray(),
        ];
        $delta = (int) $data['jumlah_kematian'] - (int) $log->jumlah_kematian;

        DB::transaction(function () use ($log, $kandang, $periode, $data, $delta) {
            $log->update([
                'jumlah_kematian' => (int) $data['jumlah_kematian'],
                'tanggal' => $data['tanggal'] ?? $log->tanggal,
            ]);

            $this->applyMortalityDelta($kandang, $periode, $delta);
        });

        ActivityLogger::log('update', 'kandang_mortality', $log, $before, [
            'log' => $log->fresh()->toArray(),
            'kandang' => $kandang->fresh()->toArray(),
            'periode' => $periode?->fresh()?->toArray(),
        ], $request);

        return response()->json(['status' => true, 'message' => 'Catatan kematian berhasil dikoreksi']);
    }

    public function deleteMortalityLog(Request $request, KandangMortalityLog $log)
    {
        if (! $this->canAccessKandang($log->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        if ($this->isFarmWorker() && (int) $log->created_by !== (int) $this->creatorId()) {
            return response()->json(['status' => false, 'message' => 'Farm worker hanya boleh hapus catatan sendiri'], 403);
        }

        $kandang = Kandang::query()->findOrFail($log->id_kandang);
        $periode = $log->id_periode ? KandangPeriode::query()->find($log->id_periode) : null;
        $before = [
            'log' => $log->toArray(),
            'kandang' => $kandang->toArray(),
            'periode' => $periode?->toArray(),
        ];

        DB::transaction(function () use ($log, $kandang, $periode) {
            $this->applyMortalityDelta($kandang, $periode, -1 * (int) $log->jumlah_kematian);
            $log->delete();
        });

        ActivityLogger::log('delete', 'kandang_mortality', $log, $before, [
            'kandang' => $kandang->fresh()->toArray(),
            'periode' => $periode?->fresh()?->toArray(),
        ], $request);

        return response()->json(['status' => true, 'message' => 'Catatan kematian berhasil dihapus']);
    }

    private function applyMortalityDelta(Kandang $kandang, ?KandangPeriode $periode, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $nextKandangTotal = max(0, (int) $kandang->total_kematian + $delta);
        $kandang->update(['total_kematian' => $nextKandangTotal]);

        if ($periode) {
            $nextPeriodTotal = max(0, (int) $periode->total_kematian + $delta);
            $periode->update(['total_kematian' => $nextPeriodTotal]);
        }
    }

    public function correctMortality(Request $request)
    {
        $data = $request->validate([
            'id_kandang' => ['required', 'integer'],
            'jumlah_koreksi' => ['required', 'integer', 'min:1'],
        ]);

        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($data['id_kandang']);

        $periode = $this->editablePeriod($kandang);

        if (! $periode) {
            return response()->json(['status' => false, 'message' => 'Periode kandang tidak ditemukan'], 404);
        }

        if ($data['jumlah_koreksi'] > $periode->total_kematian) {
            return response()->json(['status' => false, 'message' => 'Jumlah koreksi melebihi total kematian tercatat'], 422);
        }

        $before = [
            'kandang' => $kandang->toArray(),
            'periode' => $periode->toArray(),
        ];

        DB::transaction(function () use ($kandang, $periode, $data) {
            $nextTotal = max(0, (int) $periode->total_kematian - (int) $data['jumlah_koreksi']);

            $periode->update(['total_kematian' => $nextTotal]);
            $kandang->update(['total_kematian' => $nextTotal]);
        });
        ActivityLogger::log('update', 'kandang', $kandang, $before, [
            'kandang' => $kandang->fresh()->toArray(),
            'periode' => $periode->fresh()->toArray(),
        ], $request);

        return response()->json(['status' => true, 'message' => 'Koreksi kematian berhasil disimpan']);
    }

    public function periods(Request $request)
    {
        $request->validate([
            'id_kandang' => ['required', 'integer'],
        ]);

        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->query('id_kandang'));

        $data = $kandang->periodes()
            ->orderByRaw("status = 'aktif' desc")
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id_periode')
            ->get()
            ->map(fn (KandangPeriode $periode) => [
                'id_periode' => $periode->id_periode,
                'id_kandang' => $periode->id_kandang,
                'nama_periode' => $periode->nama_periode,
                'label' => sprintf(
                    '%s (%s - %s)',
                    $periode->nama_periode,
                    $periode->tanggal_mulai?->toDateString() ?? '-',
                    $periode->tanggal_selesai?->toDateString() ?? 'aktif'
                ),
                'populasi_awal' => $periode->populasi_awal,
                'total_kematian' => $periode->total_kematian,
                'ayam_hidup' => max(0, $periode->populasi_awal - $periode->total_kematian),
                'tanggal_mulai' => $periode->tanggal_mulai?->toDateString(),
                'tanggal_selesai' => $periode->tanggal_selesai?->toDateString(),
                'status' => $periode->status,
            ]);

        return response()->json($data);
    }

    public function storePeriod(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $request->validate([
            'id_kandang' => [
                'required',
                'integer',
                Rule::in($this->accessibleKandangIds()),
            ],
            'nama_periode' => ['nullable', 'string', 'max:255'],
            'populasi_awal' => ['required', 'integer', 'min:0'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($data['id_kandang']);

        $periodeCount = $kandang->periodes()->count() + 1;
        $periode = DB::transaction(function () use ($data, $kandang, $periodeCount) {
            $previousPeriodEnd = Carbon::parse($data['tanggal_mulai'])->subDay()->toDateString();

            $kandang->periodes()
                ->where('status', 'aktif')
                ->whereNull('tanggal_selesai')
                ->update([
                    'tanggal_selesai' => $previousPeriodEnd,
                    'status' => 'selesai',
                ]);

            $periode = KandangPeriode::create([
                'user_id' => $this->dataOwnerIdForKandang($kandang->id_kandang),
                'created_by' => $this->creatorId(),
                'id_kandang' => $kandang->id_kandang,
                'nama_periode' => $data['nama_periode'] ?? "Periode $periodeCount",
                'populasi_awal' => $data['populasi_awal'],
                'total_kematian' => 0,
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
                'status' => empty($data['tanggal_selesai']) ? 'aktif' : 'selesai',
            ]);

            $kandang->update([
                'populasi' => $data['populasi_awal'],
                'total_kematian' => 0,
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
            ]);

            return $periode;
        });
        ActivityLogger::log('create', 'kandang_periode', $periode, null, $periode->toArray(), $request);

        return response()->json(['status' => true, 'message' => 'Periode baru berhasil dibuat', 'data' => $periode], 201);
    }

    public function addMortalityFromRequest(Request $request)
    {
        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id_kandang'));

        return $this->addMortality($request, $kandang);
    }

    public function destroy(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id_kandang'));

        $before = $kandang->toArray();
        $kandang->delete();
        ActivityLogger::log('delete', 'kandang', $kandang, $before, null, $request);

        return response()->json(['status' => true, 'message' => 'Data kandang berhasil dihapus']);
    }
}
