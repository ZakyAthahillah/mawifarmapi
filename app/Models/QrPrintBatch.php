<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrPrintBatch extends Model
{
    protected $guarded = [];

    public static function weightColumns(): array
    {
        return array_map(fn (int $index) => "berat{$index}", range(1, 30));
    }

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'user_id' => 'integer',
            'created_by' => 'integer',
            ...array_fill_keys(self::weightColumns(), 'decimal:2'),
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kandang(): BelongsTo
    {
        return $this->belongsTo(Kandang::class, 'id_kandang', 'id_kandang');
    }
}
