<?php

namespace App\Models;

use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use DashboardModel;
    use Searchable;

    protected $table = 'languages';

    /**
     * `default` and `app_scope` were missing here while LanguageRequest validated
     * both, so the dashboard's language form silently discarded whatever the user
     * picked for them — marking a language as default did nothing. MySQL hid the
     * `app_scope` half by quietly substituting the first enum value on insert;
     * SQLite refuses, which is how it surfaced.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_en',
        'code',
        'country_code',
        'default',
        'is_rtl',
        'app_scope',
        'icon',
        'app_file',
        'panel_file',
        'web_file',
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
