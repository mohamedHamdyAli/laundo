<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Trait\Scopes\Searchable;

class Language extends Model
{
    use Searchable;

    protected $table = 'languages';
    protected $fillable = [
        'name',
        'name_en',
        'code',
        'country_code',
        'is_rtl',
        'icon',
        'app_file',
        'panel_file'
    ];
    public static function getLanguageByCode($code)
    {
        return self::where('code', $code)->first();
    }

    public static function getAllLanguages()
    {
        return self::all();
    }
}
