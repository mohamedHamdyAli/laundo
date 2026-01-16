<?php

namespace App\Modules\Setting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
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
