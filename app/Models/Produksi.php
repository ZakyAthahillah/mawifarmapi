<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Produksi extends Model
{
    protected $table = 'produksi';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'created_by',
        'id_kandang',
        'id_periode',
        'tanggal',
        'harga_per_kg',
        'total_harga',
        'berat1',
        'berat2',
        'berat3',
        'berat4',
        'berat5',
        'berat6',
        'berat7',
        'berat8',
        'berat9',
        'berat10',
        'berat11',
        'berat12',
        'berat13',
        'berat14',
        'berat15',
        'berat16',
        'berat17',
        'berat18',
        'berat19',
        'berat20',
        'berat21',
        'berat22',
        'berat23',
        'berat24',
        'berat25',
        'berat26',
        'berat27',
        'berat28',
        'berat29',
        'berat30',
    ];

    public const BERAT_COLUMNS = [
        'berat1', 'berat2', 'berat3', 'berat4', 'berat5',
        'berat6', 'berat7', 'berat8', 'berat9', 'berat10',
        'berat11', 'berat12', 'berat13', 'berat14', 'berat15',
        'berat16', 'berat17', 'berat18', 'berat19', 'berat20',
        'berat21', 'berat22', 'berat23', 'berat24', 'berat25',
        'berat26', 'berat27', 'berat28', 'berat29', 'berat30',
    ];

    protected $casts = [
        'id_kandang' => 'integer',
        'id_periode' => 'integer',
        'tanggal' => 'date:Y-m-d',
        'harga_per_kg' => EncryptedDecimal::class,
        'total_harga' => EncryptedDecimal::class,
    ];

    public function getCasts(): array
    {
        return parent::getCasts() + array_fill_keys(self::BERAT_COLUMNS, EncryptedDecimal::class);
    }

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
