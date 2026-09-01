<?php

namespace App\Modules\LaundryService\Models;

use App\Modules\Service\Models\Service;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A laundry's declaration that it provides a given service.
 *
 * The entirety of a tenant's control over the catalogue: it picks what it
 * offers, never what it costs. BelongsToLaundry both filters reads to its own
 * rows and forces laundry_id on create, so a forged payload cannot enable a
 * service on another tenant's behalf.
 *
 * @property int $id
 * @property int $laundry_id
 * @property int $service_id
 * @property string $status
 */
class LaundryService extends Model
{
    use BelongsToLaundry;
    use DashboardModel;

    protected $fillable = ['laundry_id', 'service_id', 'status'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
