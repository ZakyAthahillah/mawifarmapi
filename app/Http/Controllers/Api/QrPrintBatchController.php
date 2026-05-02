<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\QrPrintBatch;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QrPrintBatchController extends Controller
{
    public function index()
    {
        $query = QrPrintBatch::query()
            ->with(['creator:id,name', 'kandang:id_kandang,nama_kandang,user_id', 'kandang.user:id,name'])
            ->orderByDesc('id');

        if (! $this->isDeveloper()) {
            $query->whereIn('id_kandang', $this->accessibleKandangIds());
        }

        return response()->json([
            'status' => true,
            'data' => $query->get()->map(fn (QrPrintBatch $batch) => $this->serialize($batch)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['tanggal'] = $data['tanggal'] ?? now()->toDateString();
        $data['nomor_batch'] = $this->nextBatchNumber((int) $data['id_kandang'], $data['tanggal']);

        $batch = QrPrintBatch::create($data + [
            'user_id' => $this->ownerIdForBatchKandang($data['id_kandang']),
            'created_by' => $this->creatorId(),
        ]);

        ActivityLogger::log('create', 'qr_print_batch', $batch, null, $batch->toArray(), $request);

        return response()->json([
            'status' => true,
            'message' => 'Batch QR berhasil disimpan.',
            'data' => $this->serialize($batch),
        ], 201);
    }

    public function update(Request $request, QrPrintBatch $batch)
    {
        if (! $this->canAccessBatch($batch)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $data = $this->validatedData($request);
        $data['tanggal'] = $data['tanggal'] ?? $batch->tanggal?->format('Y-m-d') ?? now()->toDateString();
        $data['user_id'] = $this->ownerIdForBatchKandang($data['id_kandang']);
        $before = $batch->toArray();
        $batch->update($data);

        ActivityLogger::log('update', 'qr_print_batch', $batch, $before, $batch->fresh()->toArray(), $request);

        return response()->json([
            'status' => true,
            'message' => 'Batch QR berhasil diupdate.',
            'data' => $this->serialize($batch->fresh()),
        ]);
    }

    public function destroy(QrPrintBatch $batch)
    {
        if (! $this->canAccessBatch($batch)) {
            return response()->json(['status' => false, 'message' => 'Data bukan milik user ini'], 403);
        }

        $before = $batch->toArray();
        $batch->delete();
        ActivityLogger::log('delete', 'qr_print_batch', $batch, $before, null);

        return response()->json(['status' => true, 'message' => 'Batch QR berhasil dihapus.']);
    }

    private function validatedData(Request $request): array
    {
        $rules = [
            'id_kandang' => ['required', 'integer', Rule::in($this->accessibleKandangIdsForBatch())],
            'tanggal' => ['nullable', 'date'],
        ];

        foreach (QrPrintBatch::weightColumns() as $column) {
            $rules[$column] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        foreach (QrPrintBatch::weightColumns() as $column) {
            $data[$column] = (float) ($data[$column] ?? 0);
        }

        return $data;
    }

    private function nextBatchNumber(int $kandangId, string $tanggal): string
    {
        $prefix = 'QR-K' . $kandangId . '-' . str_replace('-', '', $tanggal);
        $latest = QrPrintBatch::query()
            ->where('id_kandang', $kandangId)
            ->where('nomor_batch', 'like', "{$prefix}-%")
            ->orderByDesc('nomor_batch')
            ->value('nomor_batch');

        $sequence = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%04d', $prefix, $sequence);
    }

    private function canAccessBatch(QrPrintBatch $batch): bool
    {
        return $this->isDeveloper() || in_array((int) $batch->id_kandang, $this->accessibleKandangIds(), true);
    }

    private function accessibleKandangIdsForBatch(): array
    {
        if (! $this->isDeveloper()) {
            return $this->accessibleKandangIds();
        }

        return Kandang::query()->pluck('id_kandang')->map(fn ($id) => (int) $id)->all();
    }

    private function ownerIdForBatchKandang(int|string $kandangId): ?int
    {
        if (! $this->isDeveloper()) {
            return $this->dataOwnerIdForKandang($kandangId);
        }

        $ownerId = Kandang::query()->whereKey($kandangId)->value('user_id');

        return $ownerId ? (int) $ownerId : null;
    }

    private function serialize(QrPrintBatch $batch): array
    {
        $weights = array_map(fn (string $column) => (float) $batch->{$column}, QrPrintBatch::weightColumns());

        return [
            'id' => $batch->id,
            'id_kandang' => $batch->id_kandang,
            'nama_kandang' => $batch->kandang?->nama_kandang,
            'primary_owner_id' => $batch->kandang?->user_id,
            'primary_owner_name' => $batch->kandang?->user?->name,
            'tanggal' => $batch->tanggal?->format('Y-m-d'),
            'nomor_batch' => $batch->nomor_batch,
            'weights' => $weights,
            'total_berat' => array_sum($weights),
            'creator_name' => $batch->creator?->name,
        ];
    }
}
