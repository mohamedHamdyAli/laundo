<?php

namespace App\Modules\LaundryService\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\Service\Repositories\ServiceRepository;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;

/**
 * A laundry declaring which services it provides.
 *
 * The tenant's entire say over the catalogue. Prices never appear here — they are
 * global and belong to the super admin.
 *
 * **A laundry asks; the platform decides.** From inside a laundry, saving this
 * screen files requests (`LaundryServiceRequestReview`) and changes nothing the
 * assigner reads until somebody approves. From the platform's side the same save
 * writes at once — an operator editing the list is the approval.
 */
class laundryServiceCrudService
{
    protected $serviceRepository;

    public function __construct(
        ServiceRepository $serviceRepository,
        private readonly LaundryServiceRequestReview $requests,
    ) {
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
            // What the laundry has asked and is waiting on, and the last refusal
            // per service with its note — so the screen can say «waiting» rather
            // than redraw the old ticks and look as if the save failed.
            'requestState' => $selectedId ? $this->requests->stateFor($selectedId) : ['pending' => [], 'rejected' => []],
            'asksForApproval' => LaundryContext::currentId() !== null,
        ];
    }

    /**
     * Replaces the offering set for one laundry — or, from inside a laundry,
     * asks for it to be replaced.
     *
     * @param  array<int, mixed>  $serviceIds
     * @return int how many services were written, or how many requests filed
     */
    public function sync($laundryId, array $serviceIds, ?User $actor = null): int
    {
        // A tenant may only ever touch its own row, whatever the payload claims.
        $tenant = LaundryContext::currentId();
        $target = $tenant ?? (int) $laundryId;

        $allowed = $this->serviceRepository->allActive()->pluck('id')->all();
        $wanted = array_values(array_intersect(array_map('intval', $serviceIds), $allowed));

        if ($tenant !== null) {
            $laundry = Laundry::withoutGlobalScopes()->findOrFail($target);

            return count($this->requests->request($laundry, $wanted, $actor));
        }

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

            // The operator's edit settles whatever the laundry had asked.
            $this->requests->supersedeFor($target);
        });

        return count($wanted);
    }
}
