<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

class EncryptedDecimal implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        try {
            return (float) Crypt::decryptString((string) $value);
        } catch (\Throwable $e) {
            return (float) $value;
        }
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }

        $normalized = number_format((float) $value, 2, '.', '');

        return Crypt::encryptString($normalized);
    }
}
