<?php

namespace App\Modules\Intro\Models;

use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class intro extends Model
{
    use DashboardModel;
    use HasFactory;
    use Searchable;

    protected $fillable = [
        'image',
        'title',
        'description',
        'order',
        'status',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getTitleAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getDescriptionAttribute($value)
    {
        return json_decode((string) $value);
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
