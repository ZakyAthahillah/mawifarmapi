<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    protected function isDeveloper(): bool
    {
        return (string) Auth::user()?->role === 'developer';
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
}
