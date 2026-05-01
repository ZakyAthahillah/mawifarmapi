<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\KandangPeriode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KandangController extends Controller
{
    public function index(Request $request)
    {
        $query = Kandang::query()->where('user_id', $this->dataOwnerId());

        if ($request->filled('name')) {
            $query->where('nama_kandang', 'like', '%'.$request->query('name').'%');
        }

        return response()->json($query->orderBy('nama_kandang')->get());
    }

    public function store(Request $request)
    {
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

        DB::transaction(function () use ($data) {
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
        });

        return response()->json(['status' => true, 'message' => 'Berhasil menambahkan kandang!'], 201);
    }

    public function showByOwner(Request $request)
    {
        $query = Kandang::query()
            ->with(['periodes' => fn ($query) => $query->orderByRaw("status = 'aktif' desc")->orderByDesc('tanggal_mulai')->orderByDesc('id_periode')])
            ->where('user_id', $this->dataOwnerId());

        if ($request->filled('owner_name') || $request->filled('name')) {
            $search = $request->query('owner_name', $request->query('name', ''));
            $query->where('nama_kandang', 'like', "%$search%");
        }

        $data = $query->orderBy('nama_kandang')
            ->get()
            ->map(function ($row) {
                $periode = $row->periodes->first();
                $row->nama_tampilan = $row->nama_kandang;
                $row->jumlah_periode = $row->periodes->count();
                $row->id_periode = $periode?->id_periode;
                $row->nama_periode = $periode?->nama_periode ?? '-';
                $row->status_periode = $periode?->status ?? '-';
                $row->populasi = $periode?->populasi_awal ?? $row->populasi;
                $row->total_kematian = $periode?->total_kematian ?? $row->total_kematian;
                $row->ayam_sekarang = max(0, (int) $row->populasi - (int) $row->total_kematian);
                $row->tanggal_mulai = $periode?->tanggal_mulai ?? $row->tanggal_mulai;
                $row->tanggal_selesai = $periode?->tanggal_selesai;
                unset($row->periodes);

                return $row;
            });

        return response()->json($data);
    }

    public function search(Request $request)
    {
        $name = $request->query('name', '');

        $data = Kandang::query()
            ->select('id_kandang', 'nama_kandang')
            ->where('user_id', $this->dataOwnerId())
            ->when($name !== '', fn ($query) => $query->where('nama_kandang', 'like', "%$name%"))
            ->orderBy('nama_kandang')
            ->get();

        return response()->json($data);
    }

    public function update(Request $request, Kandang $kandang)
    {
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

        return response()->json(['status' => true, 'message' => 'Success']);
    }

    public function updateFromRequest(Request $request)
    {
        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id_kandang'));

        return $this->update($request, $kandang);
    }

    public function setPeriode(Request $request, Kandang $kandang)
    {
        $data = $request->validate([
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        if ((int) $kandang->user_id !== (int) $this->dataOwnerId()) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $kandang->update($data);

        $periode = $this->editablePeriod($kandang);

        if ($periode) {
            $periode->update([
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
                'status' => empty($data['tanggal_selesai']) ? 'aktif' : 'selesai',
            ]);
        }

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
        $data = $request->validate(['jumlah_kematian' => ['required', 'integer', 'min:1']]);

        if ((int) $kandang->user_id !== (int) $this->dataOwnerId()) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $kandang->increment('total_kematian', $data['jumlah_kematian']);

        $periode = $this->editablePeriod($kandang);

        if ($periode) {
            $periode->increment('total_kematian', $data['jumlah_kematian']);
        }

        return response()->json(['status' => true, 'message' => 'Success']);
    }

    public function correctMortality(Request $request)
    {
        $data = $request->validate([
            'id_kandang' => ['required', 'integer'],
            'jumlah_koreksi' => ['required', 'integer', 'min:1'],
        ]);

        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($data['id_kandang']);

        $periode = $this->editablePeriod($kandang);

        if (! $periode) {
            return response()->json(['status' => false, 'message' => 'Periode kandang tidak ditemukan'], 404);
        }

        if ($data['jumlah_koreksi'] > $periode->total_kematian) {
            return response()->json(['status' => false, 'message' => 'Jumlah koreksi melebihi total kematian tercatat'], 422);
        }

        DB::transaction(function () use ($kandang, $periode, $data) {
            $nextTotal = max(0, (int) $periode->total_kematian - (int) $data['jumlah_koreksi']);

            $periode->update(['total_kematian' => $nextTotal]);
            $kandang->update(['total_kematian' => $nextTotal]);
        });

        return response()->json(['status' => true, 'message' => 'Koreksi kematian berhasil disimpan']);
    }

    public function periods(Request $request)
    {
        $request->validate([
            'id_kandang' => ['required', 'integer'],
        ]);

        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
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
        $data = $request->validate([
            'id_kandang' => [
                'required',
                'integer',
                Rule::exists('kandang', 'id_kandang')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
            ],
            'nama_periode' => ['nullable', 'string', 'max:255'],
            'populasi_awal' => ['required', 'integer', 'min:0'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
        ]);

        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
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
                'user_id' => $this->dataOwnerId(),
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

        return response()->json(['status' => true, 'message' => 'Periode baru berhasil dibuat', 'data' => $periode], 201);
    }

    public function addMortalityFromRequest(Request $request)
    {
        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id_kandang'));

        return $this->addMortality($request, $kandang);
    }

    public function destroy(Request $request)
    {
        $kandang = Kandang::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id_kandang'));

        $kandang->delete();

        return response()->json(['status' => true, 'message' => 'Data kandang berhasil dihapus']);
    }
}
