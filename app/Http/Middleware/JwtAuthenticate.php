<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->cookie((string) config('jwt.cookie_name', 'mawifarm_token'));

        if (! $token) {
            return response()->json(['status' => false, 'message' => 'Token tidak ditemukan'], 401);
        }

        try {
            $payload = app(JwtService::class)->decode($token);

            if (Cache::has('jwt:blacklist:'.$payload['jti'])) {
                return response()->json(['status' => false, 'message' => 'Token sudah logout'], 401);
            }

            $user = User::find($payload['sub']);
            if (! $user) {
                return response()->json(['status' => false, 'message' => 'User tidak ditemukan'], 401);
            }

            Auth::setUser($user);
            $request->attributes->set('jwt_payload', $payload);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 401);
        }

        return $next($request);
    }
}
