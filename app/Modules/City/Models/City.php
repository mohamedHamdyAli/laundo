<?php

namespace App\Modules\City\Models;

use App\Modules\Country\Models\Country;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use Searchable;

    protected $fillable = ["name", "country_id", "status"];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    public function getNameAttribute($value)
    {
        return json_decode($value);
    }
    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
