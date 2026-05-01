<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'owner_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function dataOwnerId(): int
    {
        if ((string) $this->role !== 'admin') {
            return (int) $this->id;
        }

        $requestedOwnerId = (int) request()->header('X-Owner-Id', request()->input('scope_owner_id', 0));
        $ownerIds = $this->ownerAccess()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        if ($requestedOwnerId > 0 && in_array($requestedOwnerId, $ownerIds, true)) {
            return $requestedOwnerId;
        }

        if ($this->owner_id && in_array((int) $this->owner_id, $ownerIds, true)) {
            return (int) $this->owner_id;
        }

        return $ownerIds[0] ?? (int) $this->id;
    }

    public function ownerAccess()
    {
        return $this->belongsToMany(User::class, 'admin_owner_access', 'admin_id', 'owner_id')
            ->where('role', 'owner')
            ->orderBy('name');
    }

    public function adminAccess()
    {
        return $this->belongsToMany(User::class, 'admin_owner_access', 'owner_id', 'admin_id')
            ->where('role', 'admin')
            ->orderBy('name');
    }

    public function kandang()
    {
        return $this->hasMany(Kandang::class, 'user_id');
    }

    public function pakanTerpakai()
    {
        return $this->hasMany(PakanTerpakai::class, 'user_id');
    }

    public function produksi()
    {
        return $this->hasMany(Produksi::class, 'user_id');
    }

    public function operasional()
    {
        return $this->hasMany(Operasional::class, 'user_id');
    }
}
