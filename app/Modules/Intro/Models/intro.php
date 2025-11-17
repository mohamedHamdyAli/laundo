<?php

namespace App\Modules\Intro\Models;

use Illuminate\Database\Eloquent\Model;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class intro extends Model
{
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
        return json_decode($value);
    }
    public function getDescriptionAttribute($value)
    {
        return json_decode($value);
    }
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
