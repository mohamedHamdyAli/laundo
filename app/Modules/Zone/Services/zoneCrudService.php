<?php

namespace App\Modules\Zone\Services;

use App\Modules\City\Models\City;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Services\ResponseService;
use App\Support\Geo\Polygon;
use Illuminate\Support\Facades\DB;

class zoneCrudService
{
    protected $zoneRepository;

    protected $responseService;

    public function __construct(
        ZoneRepository $zoneRepository,
        ResponseService $responseService,
        private readonly ZoneLocator $locator,
    ) {
        $this->zoneRepository = $zoneRepository;
        $this->responseService = $responseService;
    }

    /**
     * What the last save or toggle did to addresses: how many moved zone, and
     * how many were left where they were because an order on them is still
     * under way. The controller puts it in the flash.
     *
     * @var array{moved: int, held: int}
     */
    private array $relocated = ['moved' => 0, 'held' => 0];

    public function addNew(array $request)
    {
        return DB::transaction(function () use ($request) {
            $payload = $this->payload($request);
            $this->guardOverlap($payload, null);

            $zone = $this->zoneRepository->create($payload);

            // A zone drawn on creation takes in the addresses already inside it
            // — switched on or off: the switch decides whether orders are taken
            // there, not where a pin is (`ZoneRepository::claiming()`).
            $this->relocated = $zone->isDrawn()
                ? $this->locator->relocateAround($zone, null)
                : ['moved' => 0, 'held' => 0];

            return $zone;
        });
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $payload = $this->payload($request);
            $this->guardOverlap($payload, (int) $request['id']);

            $before = $this->zoneRepository->findById($request['id']);
            $drawingBefore = $before->boundary;

            $zone = $this->zoneRepository->update($request['id'], $payload);

            // Redrawn: the addresses it now covers — or stops covering — move
            // with it, so their orders follow the map. Switching a zone off is
            // not a redraw: its addresses keep it, as they always have.
            $this->relocated = $zone->boundary !== $drawingBefore
                ? $this->locator->relocateAround($zone, $before->polygon())
                : ['moved' => 0, 'held' => 0];

            return $zone;
        });
    }

    /**
     * @return array{moved: int, held: int}
     */
    public function relocated(): array
    {
        return $this->relocated;
    }

    /**
     * The overlap rule again, inside the save's transaction with the
     * neighbours locked — ZoneRequest checked it too, but outside any lock.
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardOverlap(array $payload, ?int $id): void
    {
        $polygon = isset($payload['boundary']) ? Polygon::fromArray($payload['boundary']) : null;

        if ($polygon) {
            $this->locator->assertNoOverlap($polygon, $id, lock: true);
        }
    }

    public function deleteRecord($id)
    {
        // laundry_zones rows cascade at the database level.
        return DB::transaction(fn () => $this->zoneRepository->delete($id));
    }

    public function shredData($id = null)
    {
        $data = [
            'zones' => $this->zoneRepository->getAllPaginated(),
            'cities' => City::where('status', 'active')->get(),
            // Drawn faintly on the map, so a zone is drawn up to its
            // neighbours rather than over them — every drawn zone but this one.
            'otherZones' => $this->zoneRepository->drawnExcept($id ? (int) $id : null)
                // What the server reads, not the raw column — a stored ring
                // that is not a usable shape is not drawn as far as anything
                // else is concerned.
                ->filter(fn ($zone) => $zone->isDrawn())
                ->map(fn ($zone) => [
                    'name' => getLocalizedValueDashboard($zone, 'name'),
                    'points' => $zone->polygon()?->points(),
                ])->values()->all(),
        ];

        if ($id) {
            $data['row'] = $this->zoneRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->zoneRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        $zone = $this->zoneRepository->findById($id);
        $response = $this->responseService->toggleStatus($zone, $status);

        // Switched off, nothing moves: that has always meant «paused». Its
        // addresses keep the zone, and since 2026-10-07 a new order from them
        // is refused until it is switched back on (`Address::isCovered()`) —
        // and a pin saved inside it meanwhile is placed in it too
        // (`ZoneRepository::claiming()`). Switched on, a drawn zone still
        // claims the pins inside it: a safety net for any address filed
        // elsewhere before 2026-10-07, when a paused zone let its pins go. It
        // finds nothing to move once no such address is left.
        $zone->refresh();

        if ($zone->status === 'active' && $zone->isDrawn()) {
            DB::transaction(fn () => $this->relocated = $this->locator->relocateAround($zone, null));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request): array
    {
        $data = array_filter([
            'city_id' => $request['city_id'] ?? null,
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        // Kept out of the array_filter above: these are meant to be clearable
        // back to null, and array_filter would silently drop the emptied value
        // so a rate could be set but never unset.
        foreach (['price_per_km', 'min_delivery_fee'] as $rate) {
            if (array_key_exists($rate, $request)) {
                $data[$rate] = $request[$rate] === '' ? null : $request[$rate];
            }
        }

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        // Only when the form sent the field: a spreadsheet row, or any caller
        // that knows nothing of the drawing, must not erase it. Blank is «not
        // drawn». The ring is stored as ZoneRequest accepted it, and its box
        // beside it for the SQL pre-filter.
        if (array_key_exists('boundary', $request)) {
            $polygon = ($request['boundary'] === null || $request['boundary'] === '')
                ? null
                : Polygon::fromArray(json_decode((string) $request['boundary'], true));

            $data['boundary'] = $polygon?->points();
            $box = $polygon?->boundingBox();

            foreach (['min_lat', 'max_lat', 'min_lng', 'max_lng'] as $edge) {
                $data[$edge] = $box[$edge] ?? null;
            }
        }

        return $data;
    }
}
