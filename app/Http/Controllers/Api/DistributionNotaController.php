<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DistributionNota;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class DistributionNotaController extends Controller
{
    public function index(Request $request)
    {
        if ($response = $this->denyNonDistribution()) {
            return $response;
        }

        $query = DistributionNota::query()
            ->with('creator:id,name')
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if ($request->filled('bulan')) {
            $query->whereMonth('tanggal', $request->query('bulan'));
        }

        if ($request->filled('kandang')) {
            $query->where('kandang', $request->query('kandang'));
        }

        return response()->json([
            'status' => true,
            'data' => $query->get()->map(fn (DistributionNota $nota) => $this->serialize($nota)),
        ]);
    }

    public function store(Request $request)
    {
        if ($response = $this->denyNonDistribution()) {
            return $response;
        }

        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        $data = $this->validatedData($request);
        $ownerId = $this->dataOwnerId();
        $data['nomor_nota'] = $this->nextNotaNumber($ownerId, $data['tanggal']);

        $nota = DistributionNota::create($data + [
            'user_id' => $ownerId,
            'created_by' => $this->creatorId(),
        ]);

        ActivityLogger::log('create', 'distribution_nota', $nota, null, $nota->toArray(), $request);

        return response()->json([
            'status' => true,
            'message' => 'Nota berhasil disimpan.',
            'data' => $this->serialize($nota),
        ], 201);
    }

    public function show(DistributionNota $nota)
    {
        if ($response = $this->denyNonDistribution()) {
            return $response;
        }

        if (! $this->canAccessNota($nota)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        return response()->json([
            'status' => true,
            'data' => $this->serialize($nota),
        ]);
    }

    public function update(Request $request, DistributionNota $nota)
    {
        if ($response = $this->denyNonDistribution()) {
            return $response;
        }

        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessNota($nota)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $data = $this->validatedData($request);
        $before = $nota->toArray();
        $nota->update($data);

        ActivityLogger::log('update', 'distribution_nota', $nota, $before, $nota->fresh()->toArray(), $request);

        return response()->json([
            'status' => true,
            'message' => 'Nota berhasil diupdate.',
            'data' => $this->serialize($nota->fresh()),
        ]);
    }

    public function destroy(DistributionNota $nota)
    {
        if ($response = $this->denyNonDistribution()) {
            return $response;
        }

        if ($response = $this->denyFarmWorker()) {
            return $response;
        }

        if (! $this->canAccessNota($nota)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $nota->toArray();
        $nota->delete();

        ActivityLogger::log('delete', 'distribution_nota', $nota, $before, null);

        return response()->json([
            'status' => true,
            'message' => 'Nota berhasil dihapus.',
        ]);
    }

    private function validatedData(Request $request): array
    {
        $rules = [
            'tanggal' => ['required', 'date'],
            'kandang' => ['required', 'string', 'max:120'],
        ];

        foreach (DistributionNota::weightColumns() as $column) {
            $rules[$column] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        foreach (DistributionNota::weightColumns() as $column) {
            $data[$column] = (float) ($data[$column] ?? 0);
        }

        return $data;
    }

    private function denyNonDistribution()
    {
        $role = (string) auth()->user()?->role;

        if (in_array($role, ['developer', 'distribution', 'admin'], true)) {
            return null;
        }

        return response()->json([
            'status' => false,
            'message' => 'Menu Nota hanya untuk role Distribution, Admin, dan Developer.',
        ], 403);
    }

    private function isAdmin(): bool
    {
        return (string) auth()->user()?->role === 'admin';
    }

    private function isDistribution(): bool
    {
        return (string) auth()->user()?->role === 'distribution';
    }

    private function nextNotaNumber(int $ownerId, string $tanggal): string
    {
        $prefix = 'DN-' . str_replace('-', '', $tanggal);
        $latest = DistributionNota::query()
            ->where('user_id', $ownerId)
            ->where('nomor_nota', 'like', "{$prefix}-%")
            ->orderByDesc('nomor_nota')
            ->value('nomor_nota');

        $sequence = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%04d', $prefix, $sequence);
    }

    private function canAccessNota(DistributionNota $nota): bool
    {
        return $this->isDeveloper()
            || $this->isAdmin()
            || $this->isDistribution();
    }

    private function serialize(DistributionNota $nota): array
    {
        $weights = array_map(fn (string $column) => (float) $nota->{$column}, DistributionNota::weightColumns());

        return [
            'id' => $nota->id,
            'user_id' => $nota->user_id,
            'created_by' => $nota->created_by,
            'creator_name' => $nota->creator?->name,
            'tanggal' => $nota->tanggal?->format('Y-m-d'),
            'kandang' => $nota->kandang,
            'nomor_nota' => $nota->nomor_nota,
            'weights' => $weights,
            'total_berat' => array_sum($weights),
            'created_at' => $nota->created_at?->toISOString(),
            'updated_at' => $nota->updated_at?->toISOString(),
        ];
    }
}
