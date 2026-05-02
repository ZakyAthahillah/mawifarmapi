<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['developer', 'admin', 'user', 'owner', 'distribution', 'farm_worker'];

    public function index()
    {
        return response()->json([
            'status' => true,
            'data' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $this->mapUser($user))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $ownerIds = $data['owner_ids'] ?? [];
        unset($data['owner_ids']);

        $user = User::create($data);
        $user->ownerAccess()->sync($ownerIds);
        ActivityLogger::log('create', 'users', $user, null, $this->loggableUser($user), $request);

        return response()->json([
            'status' => true,
            'message' => 'User berhasil ditambahkan',
            'data' => $this->mapUser($user),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validatedData($request, $user->id);
        $ownerIds = $data['owner_ids'] ?? [];
        unset($data['owner_ids']);

        if (
            (string) $user->role === 'developer'
            && (string) ($data['role'] ?? '') !== 'developer'
            && $this->isLastDeveloper($user)
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak bisa mengubah role developer terakhir',
            ], 422);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $before = $this->loggableUser($user);
        $user->update($data);
        $user->ownerAccess()->sync($ownerIds);
        ActivityLogger::log('update', 'users', $user, $before, $this->loggableUser($user->fresh()), $request);

        return response()->json([
            'status' => true,
            'message' => 'User berhasil diupdate',
            'data' => $this->mapUser($user->fresh()),
        ]);
    }

    public function destroy(User $user)
    {
        if ((int) $user->id === (int) auth()->id()) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak bisa menghapus akun sendiri',
            ], 422);
        }

        if ($this->isLastDeveloper($user)) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak bisa menghapus developer terakhir',
            ], 422);
        }

        $before = $this->loggableUser($user);
        $user->delete();
        ActivityLogger::log('delete', 'users', $user, $before, null, request());

        return response()->json([
            'status' => true,
            'message' => 'User berhasil dihapus',
        ]);
    }

    private function validatedData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($ignoreId),
            ],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($ignoreId),
            ],
            'password' => [$ignoreId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(self::ROLES)],
            'must_change_password' => ['nullable', 'boolean'],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'owner')),
            ],
            'owner_ids' => ['nullable', 'array'],
            'owner_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'owner')),
            ],
        ]);

        if (! in_array(($data['role'] ?? ''), ['admin', 'farm_worker'], true)) {
            $data['owner_id'] = null;
            $data['owner_ids'] = [];
        } else {
            $ownerIds = collect($data['owner_ids'] ?? [])
                ->when($data['owner_id'] ?? null, fn ($items) => $items->push((int) $data['owner_id']))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $data['owner_id'] = $ownerIds[0] ?? null;
            $data['owner_ids'] = $ownerIds;
        }

        $data['must_change_password'] = (bool) ($data['must_change_password'] ?? false);

        return $data;
    }

    private function mapUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'owner_id' => $user->owner_id,
            'must_change_password' => (bool) $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'last_logout_at' => $user->last_logout_at?->toISOString(),
            'owner_ids' => in_array($user->role, ['admin', 'farm_worker'], true) ? $user->ownerAccess()->pluck('users.id')->map(fn ($id) => (int) $id)->values() : [],
            'owner_names' => in_array($user->role, ['admin', 'farm_worker'], true) ? $user->ownerAccess()->pluck('users.name')->values() : [],
            'owner_name' => $user->owner_id ? User::query()->whereKey($user->owner_id)->value('name') : null,
            'created_at' => optional($user->created_at)?->format('Y-m-d H:i'),
        ];
    }

    private function isLastDeveloper(User $user): bool
    {
        return (string) $user->role === 'developer'
            && User::query()->where('role', 'developer')->whereKeyNot($user->id)->doesntExist();
    }

    private function loggableUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'owner_id' => $user->owner_id,
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
