<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request, JwtService $jwt)
    {
        if (! config('auth.public_register')) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi publik dinonaktifkan',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $data['role'] = 'user';
        $data['owner_id'] = null;

        $user = User::create($data);

        $token = $jwt->createToken($user);

        return $this->cookieResponse(response()->json([
            'status' => true,
            'message' => 'Sign Up Berhasil',
            'data' => $this->userData($user),
            'token' => $token,
        ], 201), $token);
    }

    public function login(Request $request, JwtService $jwt)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['status' => false, 'message' => 'Username atau password salah'], 401);
        }

        $token = $jwt->createToken($user);

        return $this->cookieResponse(response()->json([
            'status' => true,
            'message' => 'Login berhasil',
            'data' => $this->userData($user),
            'token' => $token,
        ]), $token);
    }

    public function logout(Request $request)
    {
        $payload = $request->attributes->get('jwt_payload');
        $seconds = max(1, (int) $payload['exp'] - time());

        Cache::put('jwt:blacklist:'.$payload['jti'], true, $seconds);

        return $this->forgetCookie(response()->json(['status' => true, 'message' => 'Logout berhasil']));
    }

    public function me()
    {
        return response()->json(['status' => true, 'data' => $this->userData(auth()->user())]);
    }

    private function userData(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'owner_id' => $user->owner_id,
            'owner_options' => $user->role === 'admin'
                ? $user->ownerAccess()->get(['users.id', 'users.name'])->map(fn (User $owner) => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                ])->values()
                : [],
        ];
    }

    private function cookieResponse($response, array $token)
    {
        return $response->cookie(
            $this->cookieName(),
            $token['access_token'],
            (int) ceil(($token['expires_in'] ?? 0) / 60),
            '/',
            null,
            $this->cookieSecure(),
            true,
            false,
            $this->cookieSameSite()
        );
    }

    private function forgetCookie($response)
    {
        return $response->withoutCookie($this->cookieName());
    }

    private function cookieName(): string
    {
        return (string) config('jwt.cookie_name', 'mawifarm_token');
    }

    private function cookieSecure(): bool
    {
        return (bool) config('jwt.cookie_secure', false);
    }

    private function cookieSameSite(): string
    {
        return (string) config('jwt.cookie_same_site', 'lax');
    }
}
