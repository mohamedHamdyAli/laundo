<?php

namespace App\Modules\LaundryService\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\Service\Repositories\ServiceRepository;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;

/**
 * A laundry declaring which services it provides.
 *
 * The tenant's entire say over the catalogue. Prices never appear here — they are
 * global and belong to the super admin.
 */
class laundryServiceCrudService
{
    protected $serviceRepository;

    public function __construct(ServiceRepository $serviceRepository)
    {
        $this->serviceRepository = $serviceRepository;
    }

    /**
     * @return array<string, mixed>
     */
    public function shredData($laundryId = null)
    {
        // Laundry is tenant-scoped, so a laundry user gets exactly its own row
        // here and a super admin gets every laundry to pick from.
        $laundries = Laundry::where('status', 'active')->get();

        $selectedId = LaundryContext::currentId()
            ?? ($laundryId ? (int) $laundryId : $laundries->first()?->id);

        $enabled = [];

        if ($selectedId) {
            // LaundryService is scoped too; the explicit where covers the super
            // admin case, where no scope applies.
            foreach (LaundryService::where('laundry_id', $selectedId)->get() as $row) {
                $enabled[$row->service_id] = $row->status;
            }
        }

        return [
            'laundries' => $laundries,
            'selectedLaundryId' => $selectedId,
            'services' => $this->serviceRepository->allActive(),
            'enabled' => $enabled,
        ];
    }

    /**
     * Replaces the offering set for one laundry.
     *
     * @param  array<int, mixed>  $serviceIds
     */
    public function sync($laundryId, array $serviceIds): int
    {
        // A tenant may only ever write its own row, whatever the payload claims.
        $target = LaundryContext::currentId() ?? (int) $laundryId;

        $allowed = $this->serviceRepository->allActive()->pluck('id')->all();
        $wanted = array_values(array_intersect(array_map('intval', $serviceIds), $allowed));

        DB::transaction(function () use ($target, $wanted) {
            LaundryService::where('laundry_id', $target)
                ->whereNotIn('service_id', $wanted ?: [0])
                ->delete();

            foreach ($wanted as $serviceId) {
                LaundryService::updateOrCreate(
                    ['laundry_id' => $target, 'service_id' => $serviceId],
                    ['status' => 'active']
                );
            }
        });

        return count($wanted);
    }
}
