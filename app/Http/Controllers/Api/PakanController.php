<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KandangPeriode;
use App\Models\PakanTerpakai;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PakanController extends Controller
{
    public function index(Request $request)
    {
        $query = PakanTerpakai::query()
            ->from('pakan_terpakai as p')
            ->join('kandang as k', 'p.id_kandang', '=', 'k.id_kandang')
            ->leftJoin('users as owner', 'k.user_id', '=', 'owner.id')
            ->leftJoin('kandang_periode as kp', 'p.id_periode', '=', 'kp.id_periode')
            ->select('p.*', 'k.nama_kandang', 'k.user_id as primary_owner_id', 'owner.name as primary_owner_name', 'kp.nama_periode')
            ->whereIn('p.id_kandang', $this->accessibleKandangIds());

        if ($request->filled('bulan')) {
            $query->whereMonth('p.tanggal', $request->query('bulan'));
        }

        if ($request->filled('id_kandang')) {
            $query->where('p.id_kandang', $request->query('id_kandang'));
        }

        if ($request->filled('id_periode')) {
            $query->where('p.id_periode', $request->query('id_periode'));
        }

        return response()->json($query->orderByDesc('p.tanggal')->get());
    }

    public function store(Request $request)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

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
            'jumlah_kg' => ['required', 'numeric', 'min:0'],
            'harga_per_kg' => ['required', 'numeric', 'min:0'],
            'total_harga' => ['nullable', 'numeric', 'min:0'],
        ]);

        $periode = $this->resolvePeriod($data);
        $data['id_periode'] = $periode?->id_periode;
        $data['id_kandang'] = $periode?->id_kandang ?? $data['id_kandang'];
        $data['total_harga'] ??= $data['jumlah_kg'] * $data['harga_per_kg'];
        $pakan = PakanTerpakai::create($data + [
            'user_id' => $this->dataOwnerIdForKandang($data['id_kandang']),
            'created_by' => $this->creatorId(),
        ]);
        ActivityLogger::log('create', 'pakan', $pakan, null, $pakan->toArray(), $request);

        return response()->json(['status' => true, 'message' => 'Berhasil simpan data pakan'], 201);
    }

    public function update(Request $request, PakanTerpakai $pakan)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

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
            'jumlah_kg' => ['required', 'numeric', 'min:0'],
            'harga_per_kg' => ['required', 'numeric', 'min:0'],
        ]);

        if (! $this->canAccessKandang($pakan->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $periode = $this->resolvePeriod($data);
        $data['id_periode'] = $periode?->id_periode;
        $data['id_kandang'] = $periode?->id_kandang ?? $data['id_kandang'];
        $data['total_harga'] = $data['jumlah_kg'] * $data['harga_per_kg'];
        $before = $pakan->toArray();
        $pakan->update($data);
        ActivityLogger::log('update', 'pakan', $pakan, $before, $pakan->fresh()->toArray(), $request);

        return response()->json(['status' => 'success', 'message' => 'Data berhasil diupdate']);
    }

    public function updateFromRequest(Request $request)
    {
        $pakan = PakanTerpakai::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->update($request, $pakan);
    }

    public function destroy(PakanTerpakai $pakan)
    {
        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessKandang($pakan->id_kandang)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $pakan->toArray();
        $pakan->delete();
        ActivityLogger::log('delete', 'pakan', $pakan, $before, null);

        return response()->json(['status' => 'success', 'message' => 'Data berhasil dihapus']);
    }

    public function destroyFromRequest(Request $request)
    {
        $pakan = PakanTerpakai::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->findOrFail($request->input('id'));

        return $this->destroy($pakan);
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
