<?php

namespace App\Modules\User\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Trait\Scopes\Searchable;
use App\Models\Role;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use Searchable;
    use DashboardModel;


    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role_id',
        'image_profile',
        'gender',
        'otp',
        'otp_expires_at',
        'status',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
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
    public function scopeAvailableUsers($query)
    {
        return $query->whereHas(
            'role',
            fn($q) =>
            $q->where('slug', Role::USER)
        )->latest();
    }


    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
