<?php

namespace App\Modules\Setting\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends Model
{
    use DashboardModel;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'key',
        'value',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getAboutAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getPrivacyPolicyAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getTermsAttribute($value)
    {
        return json_decode((string) $value);
    }
}
