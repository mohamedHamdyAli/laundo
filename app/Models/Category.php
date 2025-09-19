<?php

namespace App\Models;

use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
        use Searchable;
    protected $fillable = [
        'name',
        'image',
        'parent_id',
        'default_price',
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

    public static function getAllActiveCatgories()
    {

        return static::where('status', 'active')->whereNull('parent_id')->get();
    }
    public static function getChildren($id)
    {
        return self::with('children')->whereParentId($id)->orderBy('id', 'asc')->get();
    }


    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->with('children');
    }
}
