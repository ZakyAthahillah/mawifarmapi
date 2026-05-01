<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KandangPeriode extends Model
{
    protected $table = 'kandang_periode';
    protected $primaryKey = 'id_periode';

    protected $fillable = [
        'user_id',
        'created_by',
        'id_kandang',
        'nama_periode',
        'populasi_awal',
        'total_kematian',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
    ];

    protected $casts = [
        'id_kandang' => 'integer',
        'populasi_awal' => 'integer',
        'total_kematian' => 'integer',
        'tanggal_mulai' => 'date:Y-m-d',
        'tanggal_selesai' => 'date:Y-m-d',
    ];

    public function kandang(): BelongsTo
    {
        return $this->belongsTo(Kandang::class, 'id_kandang', 'id_kandang');
    }
}
