<?php

namespace App\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use DashboardModel;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_system',
    ];

    public const USER = 'user';
    public const ADMIN = 'admin';
    public const EMPLOYEE = 'employee';


    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
