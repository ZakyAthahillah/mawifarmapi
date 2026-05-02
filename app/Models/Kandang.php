<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kandang extends Model
{
    protected $table = 'kandang';
    protected $primaryKey = 'id_kandang';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'created_by',
        'nama_kandang',
        'kapasitas',
        'populasi',
        'total_kematian',
        'tanggal_mulai',
        'tanggal_selesai',
    ];

    protected $casts = [
        'kapasitas' => 'integer',
        'populasi' => 'integer',
        'total_kematian' => 'integer',
        'tanggal_mulai' => 'date:Y-m-d',
        'tanggal_selesai' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function periodes(): HasMany
    {
        return $this->hasMany(KandangPeriode::class, 'id_kandang', 'id_kandang');
    }

    public function sharedOwners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kandang_owner_access', 'id_kandang', 'owner_id')
            ->where('role', 'owner')
            ->orderBy('name');
    }
}
