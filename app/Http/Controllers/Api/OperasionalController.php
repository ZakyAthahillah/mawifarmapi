<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KandangPeriode;
use App\Models\Operasional;
use App\Models\PakanTerpakai;
use App\Models\Produksi;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperasionalController extends Controller
{
    public function index(Request $request)
    {
        $query = Operasional::query()
            ->from('operasional as o')
            ->join('kandang as k', 'o.id_kandang', '=', 'k.id_kandang')
            ->leftJoin('users as owner', 'k.user_id', '=', 'owner.id')
            ->leftJoin('kandang_periode as kp', 'o.id_periode', '=', 'kp.id_periode')
            ->select('o.*', 'o.id_operasional as id', 'k.nama_kandang', 'k.user_id as primary_owner_id', 'owner.name as primary_owner_name', 'kp.nama_periode')
            ->whereIn('o.id_kandang', $this->accessibleKandangIds());

        if ($request->filled('bulan')) {
            $query->whereMonth('o.tanggal', $request->query('bulan'));
        }

        if ($request->filled('id_kandang')) {
            $query->where('o.id_kandang', $request->query('id_kandang'));
        }

        if ($request->filled('id_periode')) {
            $query->where('o.id_periode', $request->query('id_periode'));
        }

        return response()->json($query->orderByDesc('o.tanggal')->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'id_kandang' => (int) $row->id_kandang,
            'id_periode' => $row->id_periode ? (int) $row->id_periode : null,
            'nama_kandang' => $row->nama_kandang,
            'primary_owner_id' => $row->primary_owner_id ? (int) $row->primary_owner_id : null,
            'primary_owner_name' => $row->primary_owner_name,
            'nama_periode' => $row->nama_periode,
            'tanggal' => $row->tanggal?->toDateString(),
            'rak' => (float) $row->rak,
            'gaji' => (float) $row->gaji,
            'lain' => (float) $row->lain,
        ]));
    }

    public function store(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $this->validatedData($request);
        $operasional = Operasional::create($data + [
            'user_id' => $this->dataOwnerIdForKandang($data['id_kandang']),
            'created_by' => $this->creatorId(),
        ]);
        ActivityLogger::log('create', 'operasional', $operasional, null, $operasional->toArray(), $request);

        return response()->json(['status' => 'success', 'message' => 'Berhasil simpan data operasional'], 201);
    }

    public function update(Request $request, Operasional $operasional)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessKandang($operasional->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $operasional->toArray();
        $operasional->update($this->validatedData($request));
        ActivityLogger::log('update', 'operasional', $operasional, $before, $operasional->fresh()->toArray(), $request);

        return response()->json(['status' => 'success', 'message' => 'Update Berhasil']);
    }

    public function updateFromRequest(Request $request)
    {
        $operasional = Operasional::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->update($request, $operasional);
    }

    public function destroy(Operasional $operasional)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessKandang($operasional->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $operasional->toArray();
        $operasional->delete();
        ActivityLogger::log('delete', 'operasional', $operasional, $before, null);

        return response()->json(['status' => 'success', 'message' => 'Data berhasil dihapus']);
    }

    public function destroyFromRequest(Request $request)
    {
        $operasional = Operasional::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->destroy($operasional);
    }

    public function yearlyRecap(Request $request)
    {
        $year = (int) $request->query('tahun', date('Y'));
        $kandangIds = $this->accessibleKandangIds();
        $months = [];

        $productionRows = Produksi::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $kandangIds)
            ->whereYear('tanggal', $year)
            ->orderBy('tanggal')
            ->get();

        foreach ($productionRows as $row) {
            $month = (int) $row->tanggal->format('n');
            $months[$month] ??= ['bulan' => $month, 'total_laba' => 0, 'detail' => []];
            $detailKey = (int) $row->id_kandang;
            $weightTotal = $this->productionWeightTotal($row);

            $months[$month]['detail'][$detailKey] ??= [
                'nama_kandang' => $row->kandang?->nama_kandang ?? '-',
                'total_berat' => 0,
                'pendapatan' => 0,
                'pakan' => 0,
                'rak' => 0,
                'gaji' => 0,
                'lain' => 0,
                'laba' => 0,
            ];

            $months[$month]['detail'][$detailKey]['total_berat'] += $weightTotal;
            $months[$month]['detail'][$detailKey]['pendapatan'] += (float) $row->total_harga;
        }

        $feedRows = PakanTerpakai::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $kandangIds)
            ->whereYear('tanggal', $year)
            ->orderBy('tanggal')
            ->get();

        foreach ($feedRows as $row) {
            $month = (int) $row->tanggal->format('n');
            $months[$month] ??= ['bulan' => $month, 'total_laba' => 0, 'detail' => []];
            $detailKey = (int) $row->id_kandang;

            $months[$month]['detail'][$detailKey] ??= [
                'nama_kandang' => $row->kandang?->nama_kandang ?? '-',
                'total_berat' => 0,
                'pendapatan' => 0,
                'pakan' => 0,
                'rak' => 0,
                'gaji' => 0,
                'lain' => 0,
                'laba' => 0,
            ];

            $months[$month]['detail'][$detailKey]['pakan'] += (float) $row->total_harga;
        }

        $operasionalRows = Operasional::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $kandangIds)
            ->whereYear('tanggal', $year)
            ->orderBy('tanggal')
            ->get();

        foreach ($operasionalRows as $row) {
            $month = (int) $row->tanggal->format('n');
            $months[$month] ??= ['bulan' => $month, 'total_laba' => 0, 'detail' => []];
            $detailKey = (int) $row->id_kandang;

            $months[$month]['detail'][$detailKey] ??= [
                'nama_kandang' => $row->kandang?->nama_kandang ?? '-',
                'total_berat' => 0,
                'pendapatan' => 0,
                'pakan' => 0,
                'rak' => 0,
                'gaji' => 0,
                'lain' => 0,
                'laba' => 0,
            ];

            $months[$month]['detail'][$detailKey]['rak'] += (float) $row->rak;
            $months[$month]['detail'][$detailKey]['gaji'] += (float) $row->gaji;
            $months[$month]['detail'][$detailKey]['lain'] += (float) $row->lain;
        }

        foreach ($months as &$monthData) {
            $monthData['detail'] = array_values($monthData['detail']);
            foreach ($monthData['detail'] as &$detail) {
                $profit = (float) $detail['pendapatan'] - ((float) $detail['pakan'] + (float) $detail['rak'] + (float) $detail['gaji'] + (float) $detail['lain']);
                $detail['laba'] = $profit;
                $monthData['total_laba'] += $profit;
            }
            unset($detail);
        }
        unset($monthData);

        return response()->json(collect(range(1, 12))->map(fn ($month) => $months[$month] ?? [
            'bulan' => $month,
            'total_laba' => 0,
            'detail' => [],
        ]));
    }

    private function productionWeightTotal(Produksi $row): float
    {
        return array_reduce(
            Produksi::BERAT_COLUMNS,
            fn (float $carry, string $column) => $carry + (float) $row->{$column},
            0.0
        );
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'id_kandang' => [
                'required_without:id_periode',
                'integer',
                Rule::in($this->accessibleKandangIds()),
            ],
            'id_periode' => [
                'nullable',
                'integer',
                Rule::exists('kandang_periode', 'id_periode')->where(fn ($query) => $query->whereIn('id_kandang', $this->accessibleKandangIds())),
            ],
            'tanggal' => ['required', 'date'],
            'rak' => ['nullable', 'numeric', 'min:0'],
            'gaji' => ['nullable', 'numeric', 'min:0'],
            'lain' => ['nullable', 'numeric', 'min:0'],
            'lainnya' => ['nullable', 'numeric', 'min:0'],
        ]);

        $periode = $this->resolvePeriod($data);

        return [
            'id_kandang' => $periode?->id_kandang ?? $data['id_kandang'],
            'id_periode' => $periode?->id_periode,
            'tanggal' => $data['tanggal'],
            'rak' => $data['rak'] ?? 0,
            'gaji' => $data['gaji'] ?? 0,
            'lain' => $data['lainnya'] ?? $data['lain'] ?? 0,
        ];
    }

    private function resolvePeriod(array $data): ?KandangPeriode
    {
        if (! empty($data['id_periode'])) {
            return KandangPeriode::query()
                ->whereIn('id_kandang', $this->accessibleKandangIds())
                ->findOrFail($data['id_periode']);
        }

        return KandangPeriode::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->where('id_kandang', $data['id_kandang'])
            ->where('tanggal_mulai', '<=', $data['tanggal'])
            ->where(fn ($query) => $query->whereNull('tanggal_selesai')->orWhere('tanggal_selesai', '>=', $data['tanggal']))
            ->orderByRaw("status = 'aktif' desc")
            ->orderByDesc('tanggal_mulai')
            ->first();
    }
}
