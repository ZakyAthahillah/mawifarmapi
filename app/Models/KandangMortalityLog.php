<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KandangMortalityLog extends Model
{
    protected $table = 'kandang_mortality_logs';

    protected $fillable = [
        'user_id',
        'created_by',
        'id_kandang',
        'id_periode',
        'tanggal',
        'jumlah_kematian',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'jumlah_kematian' => 'integer',
    ];

    public function kandang(): BelongsTo
    {
        return $this->belongsTo(Kandang::class, 'id_kandang', 'id_kandang');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(KandangPeriode::class, 'id_periode', 'id_periode');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
