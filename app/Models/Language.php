<?php

namespace App\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use App\Trait\Scopes\Searchable;

class Language extends Model
{
    use Searchable;
    use DashboardModel;

    protected $table = 'languages';
    protected $fillable = [
        'name',
        'name_en',
        'code',
        'country_code',
        'is_rtl',
        'icon',
        'app_file',
        'panel_file',
        'web_file'
    ];
    public static function getLanguageByCode($code)
    {
        return cache()->rememberForever(
            "language_{$code}",
            fn () => self::where('code', $code)->first()
        );
    }

    public static function getAllLanguages()
    {
        return cache()->rememberForever(
            'all_languages',
            fn () => self::all()
        );
    }

}
