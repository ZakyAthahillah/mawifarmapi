<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\KandangOwnerAccess;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KandangAccessController extends Controller
{
    public function index()
    {
        $kandang = Kandang::query()
            ->with(['user:id,name', 'sharedOwners:id,name'])
            ->orderBy('nama_kandang')
            ->get()
            ->map(fn (Kandang $row) => [
                'id_kandang' => $row->id_kandang,
                'nama_kandang' => $row->nama_kandang,
                'primary_owner_id' => $row->user_id,
                'primary_owner_name' => $row->user?->name,
                'shared_owner_ids' => $row->sharedOwners->pluck('id')->map(fn ($id) => (int) $id)->values(),
                'shared_owner_names' => $row->sharedOwners->pluck('name')->values(),
            ]);

        return response()->json([
            'status' => true,
            'data' => [
                'kandang' => $kandang,
                'owners' => User::query()
                    ->where(fn ($query) => $query
                        ->where('role', 'owner')
                        ->orWhere('id', $this->currentUserId())
                    )
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->values(),
            ],
        ]);
    }

    public function update(Request $request, Kandang $kandang)
    {
        $data = $request->validate([
            'owner_ids' => ['nullable', 'array'],
            'owner_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 'owner')
                    ->orWhere('id', $this->currentUserId())
                ),
            ],
        ]);

        $ownerIds = collect($data['owner_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $kandang->user_id)
            ->unique()
            ->values()
            ->all();

        $before = $kandang->sharedOwners()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();
        $kandang->sharedOwners()->sync($ownerIds);
        $after = $kandang->sharedOwners()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();

        ActivityLogger::log('update_access', 'kandang_access', $kandang, ['owner_ids' => $before], ['owner_ids' => $after], $request);

        return response()->json([
            'status' => true,
            'message' => 'Akses kandang berhasil disimpan',
            'data' => KandangOwnerAccess::query()->where('id_kandang', $kandang->id_kandang)->get(),
        ]);
    }
}
