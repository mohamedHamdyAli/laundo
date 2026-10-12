<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Repositories\PointAccountRepository;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use RuntimeException;

/**
 * «نقاط العملاء» — every customer's points, for operations.
 *
 * Nothing here sets a balance: a correction is an `adjustment` row, with a note
 * and the name of whoever wrote it, through `PointsService` like every other
 * change.
 *
 * **Never inside a laundry**, whatever it was granted. The accounts are every
 * customer on the platform and not tenant-scoped, so a laundry holding
 * `point_account.view` would read customers who never ordered from it.
 */
class pointAccountCrudService
{
    public function __construct(
        private readonly PointAccountRepository $repository,
        private readonly PointsService $points,
        private readonly PointsPolicy $policy,
    ) {}

    /**
     * The list under `accounts`, with its totals; with `$id` (a customer's user
     * id) that customer under `row`, their account and their ledger.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null, ?string $term = null): array
    {
        $this->refuseInsideALaundry();

        if ($id === null) {
            return [
                'accounts' => $this->repository->paginate($term),
                'totals' => $this->repository->totals($this->policy->expiryWarningDays() ?: 30),
                'policy' => $this->policy->summary(),
            ];
        }

        $customer = $this->repository->customer($id);

        if (! $customer) {
            throw new RuntimeException('not_a_customer');
        }

        $account = $this->repository->accountOf($customer);

        return [
            'row' => $customer,
            'account' => $account,
            'available' => $this->points->available($customer),
            'nextExpiry' => $this->points->nextExpiry($customer),
            'reconciled' => $account?->isReconciled() ?? true,
            'ledgerBalance' => $account?->ledgerBalance() ?? 0,
            'transactions' => $this->repository->ledger($customer),
            'policy' => $this->policy->summary(),
        ];
    }

    /**
     * Add or take points by hand — «تسوية».
     *
     * Added points are a lot like any other and expire on the programme's
     * terms; taken points come off the soonest-expiring first.
     *
     * @throws RuntimeException `not_a_customer`, `insufficient_points`
     */
    public function adjust(int|string $userId, string $direction, int $points, string $note, User $actor): PointTransaction
    {
        $this->refuseInsideALaundry();

        $customer = $this->repository->customer($userId);

        if (! $customer) {
            throw new RuntimeException('not_a_customer');
        }

        return $direction === PointTransaction::CREDIT
            ? $this->points->credit($customer, $points, PointReason::Adjustment, null, $note, $actor)
            : $this->points->debit($customer, $points, PointReason::Adjustment, null, $note, $actor);
    }

    private function refuseInsideALaundry(): void
    {
        if (LaundryContext::currentId() !== null) {
            throw new RuntimeException('not_for_a_laundry');
        }
    }
}
