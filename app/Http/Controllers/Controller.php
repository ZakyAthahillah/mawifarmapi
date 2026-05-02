<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    protected function isDeveloper(): bool
    {
        return (string) Auth::user()?->role === 'developer';
    }

    protected function isFarmWorker(): bool
    {
        return (string) Auth::user()?->role === 'farm_worker';
    }

    protected function denyFarmWorker()
    {
        if (! $this->isFarmWorker()) {
            return null;
        }

        return response()->json([
            'status' => false,
            'message' => 'Farm worker hanya boleh mencatat dan mengoreksi kematian ayam.',
        ], 403);
    }

    protected function currentUserId(): ?int
    {
        return Auth::id();
    }

    protected function dataOwnerId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return method_exists($user, 'dataOwnerId')
            ? $user->dataOwnerId()
            : (int) $user->id;
    }

    protected function creatorId(): ?int
    {
        return Auth::id();
    }

    protected function accessibleKandangIds(): array
    {
        $ownerId = $this->dataOwnerId();

        if (! $ownerId) {
            return [];
        }

        $owned = DB::table('kandang')
            ->where('user_id', $ownerId)
            ->pluck('id_kandang');

        $shared = DB::table('kandang_owner_access')
            ->where('owner_id', $ownerId)
            ->pluck('id_kandang');

        return $owned->merge($shared)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    protected function canAccessKandang(int|string|null $kandangId): bool
    {
        if (! $kandangId) {
            return false;
        }

        return in_array((int) $kandangId, $this->accessibleKandangIds(), true);
    }

    protected function dataOwnerIdForKandang(int|string|null $kandangId): ?int
    {
        if (! $kandangId || ! $this->canAccessKandang($kandangId)) {
            return null;
        }

        $ownerId = DB::table('kandang')->where('id_kandang', $kandangId)->value('user_id');

        return $ownerId ? (int) $ownerId : null;
    }
}
