<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory, SoftDeletes;

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
        return json_decode($value);
    }
    public function getPrivacyPolicyAttribute($value)
    {
        return json_decode($value);
    }
    public function getTermsAttribute($value)
    {
        return json_decode($value);
    }
}
