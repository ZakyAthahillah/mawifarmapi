<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KandangPeriode;
use App\Models\PakanTerpakai;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PakanController extends Controller
{
    public function index(Request $request)
    {
        $query = PakanTerpakai::query()
            ->from('pakan_terpakai as p')
            ->join('kandang as k', 'p.id_kandang', '=', 'k.id_kandang')
            ->leftJoin('kandang_periode as kp', 'p.id_periode', '=', 'kp.id_periode')
            ->select('p.*', 'k.nama_kandang', 'kp.nama_periode')
            ->where('p.user_id', $this->dataOwnerId())
            ->where('k.user_id', $this->dataOwnerId());

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
        $data = $request->validate([
            'id_kandang' => [
                'required_without:id_periode',
                'integer',
                Rule::exists('kandang', 'id_kandang')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
            ],
            'id_periode' => [
                'nullable',
                'integer',
                Rule::exists('kandang_periode', 'id_periode')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
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
        PakanTerpakai::create($data + [
            'user_id' => $this->dataOwnerId(),
            'created_by' => $this->creatorId(),
        ]);

        return response()->json(['status' => true, 'message' => 'Berhasil simpan data pakan'], 201);
    }

    public function update(Request $request, PakanTerpakai $pakan)
    {
        $data = $request->validate([
            'id_kandang' => [
                'required_without:id_periode',
                'integer',
                Rule::exists('kandang', 'id_kandang')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
            ],
            'id_periode' => [
                'nullable',
                'integer',
                Rule::exists('kandang_periode', 'id_periode')->where(fn ($query) => $query->where('user_id', $this->dataOwnerId())),
            ],
            'tanggal' => ['required', 'date'],
            'jumlah_kg' => ['required', 'numeric', 'min:0'],
            'harga_per_kg' => ['required', 'numeric', 'min:0'],
        ]);

        if ((int) $pakan->user_id !== (int) $this->dataOwnerId()) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $periode = $this->resolvePeriod($data);
        $data['id_periode'] = $periode?->id_periode;
        $data['id_kandang'] = $periode?->id_kandang ?? $data['id_kandang'];
        $data['total_harga'] = $data['jumlah_kg'] * $data['harga_per_kg'];
        $pakan->update($data);

        return response()->json(['status' => 'success', 'message' => 'Data berhasil diupdate']);
    }

    public function updateFromRequest(Request $request)
    {
        $pakan = PakanTerpakai::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id'));

        return $this->update($request, $pakan);
    }

    public function destroy(PakanTerpakai $pakan)
    {
        if ((int) $pakan->user_id !== (int) $this->dataOwnerId()) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $pakan->delete();

        return response()->json(['status' => 'success', 'message' => 'Data berhasil dihapus']);
    }

    public function destroyFromRequest(Request $request)
    {
        $pakan = PakanTerpakai::query()
            ->where('user_id', $this->dataOwnerId())
            ->findOrFail($request->input('id'));

        return $this->destroy($pakan);
    }

    private function resolvePeriod(array $data): ?KandangPeriode
    {
        if (! empty($data['id_periode'])) {
            return KandangPeriode::query()
                ->where('user_id', $this->dataOwnerId())
                ->findOrFail($data['id_periode']);
        }

        return KandangPeriode::query()
            ->where('user_id', $this->dataOwnerId())
            ->where('id_kandang', $data['id_kandang'])
            ->where('tanggal_mulai', '<=', $data['tanggal'])
            ->where(fn ($query) => $query->whereNull('tanggal_selesai')->orWhere('tanggal_selesai', '>=', $data['tanggal']))
            ->orderByRaw("status = 'aktif' desc")
            ->orderByDesc('tanggal_mulai')
            ->first();
    }
}
