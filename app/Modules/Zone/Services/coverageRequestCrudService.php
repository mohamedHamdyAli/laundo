<?php

namespace App\Modules\Zone\Services;

use App\Modules\Address\Models\Address;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\CoverageRequest;
use App\Modules\Zone\Repositories\CoverageRequestRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «طلبات خارج التغطية»: writing down who asked, and marking them rung.
 *
 * There is no create or edit screen. Every row comes from a refused order or
 * quote (`OutOfCoverage`), and the only thing an operator does to one is call
 * the customer — so `shredData()` returns the list and, given an id, the single
 * row under `row`, and there is no `addNew()`.
 */
class coverageRequestCrudService
{
    public function __construct(private readonly CoverageRequestRepository $requests) {}

    /**
     * Note that this customer tried to order to or from this address.
     *
     * The first attempt makes the row; every later one counts on it and
     * refreshes the copy of the pin and the street, which the customer may have
     * edited in between.
     *
     * Never throws. It is called while the customer is being refused, and a
     * failure to write the note must not turn «we do not serve your area» into
     * «something went wrong» — the same rule as every notification here.
     */
    public function record(User $customer, Address $address): ?CoverageRequest
    {
        try {
            return DB::transaction(function () use ($customer, $address) {
                $snapshot = [
                    'lat' => $address->lat,
                    'lng' => $address->lng,
                    'address_line' => $this->line($address),
                ];

                $row = $this->requests->firstOrCreateFor(
                    $customer->id,
                    $address->id,
                    $snapshot + ['attempts' => 1, 'last_attempt_at' => now()]
                );

                if (! $row->wasRecentlyCreated) {
                    // In SQL, `attempts = attempts + 1`: two refusals at once
                    // both count, which a read-then-write would not.
                    //
                    // And back on the call list: the app has just promised them
                    // again «we will contact you», so an earlier «we rang them»
                    // — likely a «not yet» — no longer answers that promise.
                    $row->increment('attempts', 1, $snapshot + [
                        'last_attempt_at' => now(),
                        'contacted_at' => null,
                        'contacted_by' => null,
                    ]);
                }

                return $row;
            });
        } catch (Throwable $e) {
            Log::warning('[coverage] could not record an out-of-coverage attempt', [
                'user' => $customer->id, 'address' => $address->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Somebody rang them — and back again.
     *
     * Toggleable, like a driver application: an operator who marks the wrong
     * row can put it back rather than lose a customer in a list of hundreds.
     */
    public function toggleContacted(CoverageRequest $request): CoverageRequest
    {
        return DB::transaction(function () use ($request) {
            $request->forceFill($request->isContacted()
                ? ['contacted_at' => null, 'contacted_by' => null]
                : ['contacted_at' => now(), 'contacted_by' => Auth::id()]
            )->save();

            return $request->refresh();
        });
    }

    /**
     * The universal view-data assembler. The list under `coverageRequests`
     * and, when an id is given, the single record under `row`.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null): array
    {
        $data = [
            'coverageRequests' => $this->requests->list(),
            'readyCount' => $this->requests->readyToCallCount(),
        ];

        if ($id) {
            $data['row'] = $this->requests->findById((int) $id);
        }

        return $data;
    }

    public function search(?string $query, int $perPage = 15)
    {
        return $this->requests->list($query, $perPage);
    }

    public function findById(int $id): CoverageRequest
    {
        return $this->requests->findById($id);
    }

    /**
     * The address as one line somebody can read down the phone.
     */
    private function line(Address $address): ?string
    {
        $parts = array_filter(
            [$address->street, $address->building, $address->landmark],
            fn ($part) => filled($part)
        );

        return $parts === [] ? null : mb_substr(implode('، ', $parts), 0, 500);
    }
}
