<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PakanTerpakai extends Model
{
    protected $table = 'pakan_terpakai';
    public $timestamps = false;

    protected $fillable = ['user_id', 'created_by', 'id_kandang', 'id_periode', 'tanggal', 'jumlah_kg', 'harga_per_kg', 'total_harga'];

    protected $casts = [
        'id_kandang' => 'integer',
        'id_periode' => 'integer',
        'tanggal' => 'date:Y-m-d',
        'jumlah_kg' => EncryptedDecimal::class,
        'harga_per_kg' => EncryptedDecimal::class,
        'total_harga' => EncryptedDecimal::class,
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
