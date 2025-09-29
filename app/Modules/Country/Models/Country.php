<?php

namespace App\Modules\Country\Models;

use App\Modules\City\Models\City;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use Searchable;

    protected $fillable = ['name', 'code', 'phone_code', 'status'];
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    public function getNameAttribute($value)
    {
        return json_decode($value);
    }
    public function cities()
    {
        return $this->hasMany(City::class);
    }
}
