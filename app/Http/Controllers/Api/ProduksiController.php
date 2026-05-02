<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KandangPeriode;
use App\Models\Produksi;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProduksiController extends Controller
{
    public function index(Request $request)
    {
        $query = Produksi::query()
            ->from('produksi as p')
            ->join('kandang as k', 'p.id_kandang', '=', 'k.id_kandang')
            ->leftJoin('users as owner', 'k.user_id', '=', 'owner.id')
            ->leftJoin('kandang_periode as kp', 'p.id_periode', '=', 'kp.id_periode')
            ->select('p.*', 'k.nama_kandang', 'k.user_id as primary_owner_id', 'owner.name as primary_owner_name', 'kp.nama_periode')
            ->whereIn('p.id_kandang', $this->accessibleKandangIds());

        if ($request->filled('bulan')) {
            $query->whereMonth('p.tanggal', $request->query('bulan'));
        }

        if ($request->filled('id_kandang') && $request->query('id_kandang') !== '0') {
            $query->where('p.id_kandang', $request->query('id_kandang'));
        }

        if ($request->filled('id_periode') && $request->query('id_periode') !== '0') {
            $query->where('p.id_periode', $request->query('id_periode'));
        }

        return response()->json($query->orderByDesc('p.tanggal')->get());
    }

    public function store(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $this->validatedData($request);
        $produksi = Produksi::create($data + [
            'user_id' => $this->dataOwnerIdForKandang($data['id_kandang']),
            'created_by' => $this->creatorId(),
        ]);
        ActivityLogger::log('create', 'produksi', $produksi, null, $produksi->toArray(), $request);

        return response()->json(['success' => true, 'message' => 'Berhasil'], 201);
    }

    public function update(Request $request, Produksi $produksi)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessKandang($produksi->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $produksi->toArray();
        $produksi->update($this->validatedData($request));
        ActivityLogger::log('update', 'produksi', $produksi, $before, $produksi->fresh()->toArray(), $request);

        return response()->json(['success' => true, 'message' => 'Berhasil update']);
    }

    public function updateFromRequest(Request $request)
    {
        $produksi = Produksi::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->update($request, $produksi);
    }

    public function destroy(Produksi $produksi)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessKandang($produksi->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $produksi->toArray();
        $produksi->delete();
        ActivityLogger::log('delete', 'produksi', $produksi, $before, null);

        return response()->json(['success' => true]);
    }

    public function destroyFromRequest(Request $request)
    {
        $produksi = Produksi::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->destroy($produksi);
    }

    private function validatedData(Request $request): array
    {
        $rules = [
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
            'harga_per_kg' => ['required', 'numeric', 'min:0'],
            'total_harga' => ['required', 'numeric', 'min:0'],
        ];

        foreach (Produksi::BERAT_COLUMNS as $column) {
            $rules[$column] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        foreach (Produksi::BERAT_COLUMNS as $column) {
            $data[$column] ??= 0;
        }

        $periode = $this->resolvePeriod($data);
        $data['id_periode'] = $periode?->id_periode;
        $data['id_kandang'] = $periode?->id_kandang ?? $data['id_kandang'];

        return $data;
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
