<?php

namespace App\Modules\LaundryZone\Models;

use App\Modules\Zone\Models\Zone;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A laundry's declaration that it serves a given zone.
 *
 * Same shape and same guarantees as LaundryService: BelongsToLaundry filters
 * reads to the actor's own rows and forces `laundry_id` on create, so a forged
 * payload cannot claim territory for another tenant.
 *
 * @property int $id
 * @property int $laundry_id
 * @property int $zone_id
 */
class LaundryZone extends Model
{
    use BelongsToLaundry;
    use DashboardModel;

    protected $fillable = ['laundry_id', 'zone_id'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
}
