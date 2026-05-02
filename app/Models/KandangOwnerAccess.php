<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KandangOwnerAccess extends Model
{
    protected $table = 'kandang_owner_access';

    protected $fillable = ['id_kandang', 'owner_id'];

    public function kandang(): BelongsTo
    {
        return $this->belongsTo(Kandang::class, 'id_kandang', 'id_kandang');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
