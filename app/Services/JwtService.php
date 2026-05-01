<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class JwtService
{
    public function createToken(User $user): array
    {
        $now = time();
        $ttl = config('jwt.ttl') * 60;
        $expiresAt = $now + $ttl;

        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'exp' => $expiresAt,
            'jti' => (string) Str::uuid(),
        ];

        return [
            'access_token' => $this->encode($payload),
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Token tidak valid');
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", $this->secret(), true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Signature token tidak valid');
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);

        if (! is_array($decoded) || empty($decoded['sub']) || empty($decoded['exp']) || empty($decoded['jti'])) {
            throw new InvalidArgumentException('Payload token tidak valid');
        }

        if ((int) $decoded['exp'] < time()) {
            throw new InvalidArgumentException('Token sudah kedaluwarsa');
        }

        return $decoded;
    }

    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "$header.$body", $this->secret(), true));

        return "$header.$body.$signature";
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET belum diset');
        }

        return Str::startsWith($secret, 'base64:') ? base64_decode(Str::after($secret, 'base64:')) : $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
