<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionNota extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'user_id' => 'integer',
            'created_by' => 'integer',
            ...self::weightCasts(),
        ];
    }

    public static function weightColumns(): array
    {
        return array_map(fn (int $index) => "berat{$index}", range(1, 50));
    }

    private static function weightCasts(): array
    {
        return array_fill_keys(self::weightColumns(), 'decimal:2');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
