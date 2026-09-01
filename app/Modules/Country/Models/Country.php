<?php

namespace App\Modules\Country\Models;

use App\Modules\City\Models\City;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['name', 'code', 'phone_code', 'timezone', 'status'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }
}
