<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Operasional extends Model
{
    protected $table = 'operasional';
    protected $primaryKey = 'id_operasional';
    public $timestamps = false;

    protected $fillable = ['user_id', 'created_by', 'id_kandang', 'id_periode', 'tanggal', 'rak', 'gaji', 'lain'];

    protected $casts = [
        'id_kandang' => 'integer',
        'id_periode' => 'integer',
        'tanggal' => 'date:Y-m-d',
        'rak' => EncryptedDecimal::class,
        'gaji' => EncryptedDecimal::class,
        'lain' => EncryptedDecimal::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function kandang(): BelongsTo
    {
        return $this->belongsTo(Kandang::class, 'id_kandang', 'id_kandang');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(KandangPeriode::class, 'id_periode', 'id_periode');
    }
}
