<?php

namespace App\Modules\LaundryZone\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;

/**
 * A laundry declaring the zones it serves.
 *
 * Mirrors laundryServiceCrudService: the tenant picks from a global list and can
 * only ever write its own rows. This is the table P6's assignment engine joins
 * against to find a laundry that covers a customer's address.
 */
class laundryZoneCrudService
{
    protected $zoneRepository;

    public function __construct(ZoneRepository $zoneRepository)
    {
        $this->zoneRepository = $zoneRepository;
    }

    /**
     * @return array<string, mixed>
     */
    public function shredData($laundryId = null)
    {
        $laundries = Laundry::where('status', 'active')->get();

        $selectedId = LaundryContext::currentId()
            ?? ($laundryId ? (int) $laundryId : $laundries->first()?->id);

        $enabled = [];

        if ($selectedId) {
            $enabled = LaundryZone::withoutGlobalScopes()
                ->where('laundry_id', $selectedId)
                ->pluck('zone_id')
                ->all();
        }

        // Grouped by city so a long zone list stays navigable.
        $zonesByCity = $this->zoneRepository->allActive()->groupBy(
            fn ($zone) => $zone->city ? getLocalizedValueDashboard($zone->city, 'name') : '—'
        );

        return [
            'laundries' => $laundries,
            'selectedLaundryId' => $selectedId,
            'zonesByCity' => $zonesByCity,
            'enabled' => $enabled,
        ];
    }

    /**
     * Replaces the served-zone set for one laundry.
     *
     * @param  array<int, mixed>  $zoneIds
     */
    public function sync($laundryId, array $zoneIds): int
    {
        // A tenant writes its own row whatever the payload claims.
        $target = LaundryContext::currentId() ?? (int) $laundryId;

        $allowed = $this->zoneRepository->allActive()->pluck('id')->all();
        $wanted = array_values(array_intersect(array_map('intval', $zoneIds), $allowed));

        DB::transaction(function () use ($target, $wanted) {
            LaundryZone::withoutGlobalScopes()
                ->where('laundry_id', $target)
                ->whereNotIn('zone_id', $wanted ?: [0])
                ->delete();

            foreach ($wanted as $zoneId) {
                LaundryZone::withoutGlobalScopes()->updateOrCreate(
                    ['laundry_id' => $target, 'zone_id' => $zoneId],
                    []
                );
            }
        });

        return count($wanted);
    }
}
