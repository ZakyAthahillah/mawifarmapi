<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array((string) $user->role, $roles, true)) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        return $next($request);
    }
}
