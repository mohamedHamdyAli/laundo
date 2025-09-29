<?php

namespace App\Modules\Banner\Models;

use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class banner extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'image',
        'name',
        'description',
        'status',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    public function getNameAttribute($value)
    {
        return json_decode($value);
    }
    public function getDescriptionAttribute($value)
    {
        return json_decode($value);
    }
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
