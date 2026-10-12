<?php

namespace App\Modules\Loyalty\Repositories;

use App\Models\Role;
use App\Modules\Loyalty\Models\PointAccount;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\PointsPolicy;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The queries behind «نقاط العملاء».
 */
class PointAccountRepository
{
    /**
     * What the list's search box reaches — every column the list displays.
     *
     * @var array<int, string>
     */
    public const SEARCH = ['owner.name', 'owner.phone', 'owner.email', 'owner.customer_reference', 'balance'];

    /**
     * Every account, biggest balance first.
     *
     * @return Builder<PointAccount>
     */
    public function query(?string $term = null): Builder
    {
        return PointAccount::query()
            // The reconciliation each row shows, in the same query.
            ->withLedger()
            ->with('owner:id,name,phone,email,customer_reference')
            ->when($term, fn (Builder $q) => $q->search($term, self::SEARCH))
            ->orderByDesc('balance')
            ->orderBy('id');
    }

    public function paginate(?string $term = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($term)->paginate($perPage);
    }

    /**
     * A customer, by id — never a driver, a laundry or a member of staff, who
     * have no points to hold.
     */
    public function customer(int|string $userId): ?User
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('role', fn (Builder $q) => $q->where('slug', Role::USER))
            ->first();
    }

    public function accountOf(User $customer): ?PointAccount
    {
        return PointAccount::where('user_id', $customer->id)->first();
    }

    public function ledger(User $customer, int $perPage = 25): LengthAwarePaginator
    {
        return PointTransaction::where('user_id', $customer->id)
            ->with('author:id,name')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The three figures the list opens with.
     *
     * @return array{outstanding: int, expiring: int, unreconciled: int}
     */
    public function totals(int $expiringWithinDays): array
    {
        return [
            // What customers can spend today, not the cached sum: a lapsed lot
            // the nightly command has not written off yet is not owed to anybody.
            'outstanding' => (int) PointTransaction::query()->live()->sum('remaining'),
            'expiring' => (int) PointTransaction::query()
                ->live()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', app(PointsPolicy::class)->warningHorizon(max($expiringWithinDays, 1)))
                ->sum('remaining'),
            // Counted in SQL: loading every account to ask each one would be a
            // query per customer on every visit.
            'unreconciled' => PointAccount::query()->outOfBalance()->count(),
        ];
    }
}
