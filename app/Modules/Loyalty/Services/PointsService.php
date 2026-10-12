<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointAccount;
use App\Modules\Loyalty\Models\PointLotSpend;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\User\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing that may change a customer's points.
 *
 * The wallet's two rules, and one of its own:
 *
 *  1. **Every change is a row.** `point_accounts.balance` is written here and
 *     nowhere else, always beside the row that explains it.
 *  2. **Every change happens under the account's lock.** Two checkouts spending
 *     the same points at once would each read the same balance and both pass a
 *     check only one should.
 *  3. **A credit is a lot, and lots are spent oldest-expiry first.** Points that
 *     never expire go last. Which lots a spend ate is recorded, so a return puts
 *     the points back with the expiry they had — a customer must not be able to
 *     refresh an expiry by placing and cancelling an order.
 *
 * Lapsed lots are written off by `loyalty:expire-points` once a day, and also
 * here, under the lock, before anything is spent — so the customer can never
 * spend a point the clock has already taken, whenever the command last ran.
 */
class PointsService
{
    public function __construct(private readonly PointsPolicy $policy) {}

    /**
     * The customer's account, created the first time it is needed.
     *
     * `firstOrCreate` is safe against two creators racing: on a miss it tries
     * the insert and, beaten to it, reads the row the other one wrote.
     */
    public function account(User $user): PointAccount
    {
        return PointAccount::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
    }

    /**
     * What the customer can spend right now: every lot with points left that
     * the clock has not taken.
     *
     * Read without writing. Between a lot lapsing and the daily command writing
     * it off, the cached balance still counts it — this does not.
     */
    public function available(User $user): int
    {
        return (int) PointTransaction::where('user_id', $user->id)->live()->sum('remaining');
    }

    /**
     * The soonest points to lapse, and how many: «30 نقطة تنتهي يوم 12 نوفمبر».
     *
     * @return array{points: int, expires_at: Carbon}|null
     */
    public function nextExpiry(User $user): ?array
    {
        $soonest = PointTransaction::where('user_id', $user->id)
            ->live()
            ->whereNotNull('expires_at')
            ->min('expires_at');

        if ($soonest === null) {
            return null;
        }

        $date = Carbon::parse($soonest);

        // Every lot lapsing on the same business day is one line to the
        // customer, not three.
        $local = $date->copy()->setTimezone(displayTimezone());

        $points = (int) PointTransaction::where('user_id', $user->id)
            ->live()
            ->whereBetween('expires_at', [
                $local->copy()->startOfDay()->utc(),
                $local->copy()->endOfDay()->utc(),
            ])
            ->sum('remaining');

        return ['points' => $points, 'expires_at' => $date];
    }

    /**
     * Add points as a new lot.
     *
     * `$key` makes an automatic credit happen once — the points for an order,
     * the hangers on a leg. A key already in the ledger returns null and writes
     * nothing, which is what makes a replayed completion harmless.
     *
     * `$expiresAt` is the lot's expiry; leave it out for the programme's own
     * (`Points_Expiry_Days` from now), or pass null for «never».
     *
     * @throws RuntimeException
     */
    public function credit(
        User $user,
        int $points,
        PointReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?User $actor = null,
        ?string $key = null,
        CarbonInterface|null|false $expiresAt = false,
    ): ?PointTransaction {
        if ($points < 1) {
            // A credit of nothing explains nothing.
            throw new RuntimeException('points_must_be_positive');
        }

        $expiry = $expiresAt === false ? $this->policy->expiresAt() : $expiresAt;

        return DB::transaction(function () use ($user, $points, $reason, $source, $note, $actor, $key, $expiry) {
            $account = $this->lock($user);

            // Lapsed lots written off first, so the balance this row records —
            // and the «رصيدك دلوقتي» the customer is told — never counts points
            // the clock has already taken.
            $this->expireLocked($account);

            // A locking read, like every read below a lock in this class: a
            // plain one sees the transaction's snapshot, which can predate
            // another transaction's commit (see lock()).
            if ($key !== null && PointTransaction::where('dedupe_key', $key)->lockForUpdate()->exists()) {
                return null;
            }

            return $this->write($account, PointTransaction::CREDIT, $points, $reason, $source, $note, $actor, $key, [
                'remaining' => $points,
                'expires_at' => $expiry,
            ]);
        });
    }

    /**
     * Take points, eating the lots that lapse first.
     *
     * @throws RuntimeException `insufficient_points` when the customer has
     *                          fewer live points than asked for
     */
    public function debit(
        User $user,
        int $points,
        PointReason $reason,
        ?Model $source = null,
        ?string $note = null,
        ?User $actor = null,
        ?string $key = null,
    ): PointTransaction {
        if ($points < 1) {
            throw new RuntimeException('points_must_be_positive');
        }

        // Whatever the clock has taken is written off first, in its own
        // transaction — so a refused spend leaves it written off when this is
        // the outermost call. Inside a caller's transaction (checkout) it stands
        // or falls with the caller, and the nightly command writes it off
        // instead. Either way it is bookkeeping: what makes a lapsed point
        // unspendable is `live()` below.
        $this->expireDue($user);

        return DB::transaction(function () use ($user, $points, $reason, $source, $note, $actor, $key) {
            $account = $this->lock($user);

            if ($key !== null && PointTransaction::where('dedupe_key', $key)->lockForUpdate()->exists()) {
                throw new RuntimeException('points_already_moved');
            }

            // Locked, not merely read: a checkout's transaction has already
            // fixed its snapshot before it reaches the lock, so a plain read
            // here showed the lots as they were before a second checkout spent
            // them — and both spent the same points (reproduced on MySQL).
            $lots = PointTransaction::where('user_id', $user->id)
                ->live()
                // Oldest expiry first; never-expiring last; ties by age.
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lots->sum('remaining') < $points) {
                throw new RuntimeException('insufficient_points');
            }

            // The row lock() returned, never a refresh(): that is a plain read
            // and would bring the stale balance back.
            $debit = $this->write($account, PointTransaction::DEBIT, $points, $reason, $source, $note, $actor, $key);

            $left = $points;

            foreach ($lots as $lot) {
                if ($left < 1) {
                    break;
                }

                $take = min($left, (int) $lot->remaining);

                $lot->update(['remaining' => (int) $lot->remaining - $take]);
                PointLotSpend::create(['debit_id' => $debit->id, 'lot_id' => $lot->id, 'points' => $take]);

                $left -= $take;
            }

            return $debit;
        });
    }

    /**
     * Give back what a spend took beyond `$keep` points.
     *
     * Said as what stays spent, not as how much to return: the caller knows
     * the first (the points the counted pieces still allow, or none for a
     * cancelled order), and the second is worked out here, under the lock,
     * from what has already come back. So two returns racing — two reviews
     * saved at once, a review and a cancellation — land on the same figure
     * instead of each returning its own share, and nothing ever comes back
     * twice.
     *
     * Each point comes back with **the expiry of the lot it came from**, the
     * longest-lived lots first (the order kindest to the customer, and the same
     * order every time, so a second return carries on where the first stopped).
     * A lot that has lapsed in the meantime does not send its points back
     * already dead — the customer did not choose to lose them — but it does not
     * send them back new either: they get the time they had left when they
     * were spent, so cancelling an order can never renew points.
     *
     * @return int the points actually returned
     */
    public function giveBack(
        PointTransaction $spend,
        int $keep = 0,
        ?Model $source = null,
        ?string $note = null,
        ?User $actor = null,
    ): int {
        if ($spend->direction !== PointTransaction::DEBIT) {
            return 0;
        }

        $owner = User::withTrashed()->find($spend->user_id);

        if (! $owner) {
            return 0;
        }

        return DB::transaction(function () use ($spend, $keep, $source, $note, $actor, $owner) {
            $account = $this->lock($owner);
            $this->expireLocked($account);

            // Locking reads: a plain sum here could miss a return another
            // transaction has just committed, and return the same points again.
            $returned = (int) PointTransaction::where('reverses_id', $spend->id)
                ->lockForUpdate()
                ->pluck('points')
                ->sum();
            $due = (int) $spend->points - $returned - max($keep, 0);

            if ($due < 1) {
                return 0;
            }

            // Longest-lived first; never-expiring first of all.
            $spends = PointLotSpend::where('debit_id', $spend->id)
                ->with(['lot' => fn ($query) => $query->lockForUpdate()])
                ->lockForUpdate()
                ->get()
                ->sortBy([
                    fn (PointLotSpend $a, PointLotSpend $b) => $this->expiryRank($b->lot?->expires_at) <=> $this->expiryRank($a->lot?->expires_at),
                    fn (PointLotSpend $a, PointLotSpend $b) => $b->lot_id <=> $a->lot_id,
                ]);

            // Skip what earlier returns already gave back, walking the same order.
            $skip = $returned;
            $left = $due;
            /** @var array<string, array{points: int, expires_at: Carbon|null, warned_at: Carbon|null}> $byExpiry */
            $byExpiry = [];

            foreach ($spends as $row) {
                $part = (int) $row->points;

                if ($skip >= $part) {
                    $skip -= $part;

                    continue;
                }

                $part -= $skip;
                $skip = 0;

                $take = min($part, $left);
                $expiry = $this->expiryOnReturn($row->lot?->expires_at, $spend->created_at);
                $slot = $expiry?->toIso8601String() ?? 'never';
                // Points coming back for the same business day they were
                // already announced for carry the announcement with them:
                // «30 نقطة تنتهي يوم 12» is not news the second time. A later
                // day is, and is announced again.
                $kept = $expiry !== null && $row->lot?->expires_at !== null
                    && $this->businessDay($row->lot->expires_at) === $this->businessDay($expiry);

                $byExpiry[$slot] ??= ['points' => 0, 'expires_at' => $expiry, 'warned_at' => null];
                $byExpiry[$slot]['points'] += $take;
                $byExpiry[$slot]['warned_at'] ??= $kept ? $row->lot?->expiry_warned_at : null;

                $left -= $take;

                if ($left < 1) {
                    break;
                }
            }

            $given = 0;

            foreach ($byExpiry as $lot) {
                // The locked row, kept current by write() itself.
                $this->write($account, PointTransaction::CREDIT, $lot['points'], PointReason::RedemptionReturned, $source, $note, $actor, null, [
                    'remaining' => $lot['points'],
                    'expires_at' => $lot['expires_at'],
                    'expiry_warned_at' => $lot['warned_at'],
                    'reverses_id' => $spend->id,
                ]);

                $given += $lot['points'];
            }

            return $given;
        });
    }

    /**
     * Write off every lapsed lot, for one customer or for everybody.
     *
     * @return int how many points were written off
     */
    public function expireDue(?User $user = null): int
    {
        $owners = PointTransaction::query()
            ->where('direction', PointTransaction::CREDIT)
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($user, fn (Builder $q) => $q->where('user_id', $user->id))
            ->distinct()
            ->pluck('user_id');

        $total = 0;

        foreach ($owners as $ownerId) {
            // With the closed accounts: their lots lapse like anybody's, or the
            // balance (and the platform's liability on «ملخص الماليات») would
            // hold them for ever.
            $owner = User::withTrashed()->find($ownerId);

            if (! $owner) {
                continue;
            }

            $total += DB::transaction(fn () => $this->expireLocked($this->lock($owner)));
        }

        return $total;
    }

    /**
     * Lock the customer's account row for the rest of the transaction.
     *
     * **Everything this class decides after the lock reads with a lock too**,
     * and writes from the row returned here. MySQL runs at REPEATABLE READ: a
     * plain SELECT sees the transaction's snapshot, fixed at its first read —
     * which in a checkout happens before the lock is even asked for — while a
     * locking read sees what is committed now. Mixing the two let two
     * checkouts spend the same points: the second waited for the lock, then
     * read the balance and the lots as they were before the first had spent
     * them (reproduced on the development MySQL, 2026-10-12). SQLite, which the
     * tests run on, serializes writers and cannot show it.
     */
    private function lock(User $user): PointAccount
    {
        try {
            $this->account($user);
        } catch (UniqueConstraintViolationException) {
            // Another transaction created it after this one's snapshot was
            // taken, so firstOrCreate()'s plain re-read cannot see it. The
            // locking read below can.
        }

        return PointAccount::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Write off this account's lapsed lots, one row each. The lock is held.
     */
    private function expireLocked(PointAccount $account): int
    {
        $lapsed = PointTransaction::where('user_id', $account->user_id)
            ->where('direction', PointTransaction::CREDIT)
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $total = 0;

        foreach ($lapsed as $lot) {
            $points = (int) $lot->remaining;

            // One row per lot, keyed on it: the history reads «30 points
            // expired» against the order that earned them, and a second run
            // cannot write the same lot off twice.
            $debit = $this->write($account, PointTransaction::DEBIT, $points, PointReason::Expired, $lot, null, null, 'expired:'.$lot->id);

            $lot->update(['remaining' => 0]);
            PointLotSpend::create(['debit_id' => $debit->id, 'lot_id' => $lot->id, 'points' => $points]);

            $total += $points;
        }

        return $total;
    }

    /**
     * The row and the balance together. Never one without the other.
     *
     * @param  array<string, mixed>  $extra
     */
    private function write(
        PointAccount $account,
        string $direction,
        int $points,
        PointReason $reason,
        ?Model $source,
        ?string $note,
        ?User $actor,
        ?string $key,
        array $extra = [],
    ): PointTransaction {
        $after = $direction === PointTransaction::CREDIT
            ? (int) $account->balance + $points
            : (int) $account->balance - $points;

        // A balance below zero is a bug somewhere above; refusing it here keeps
        // the bug from becoming a permanent row.
        if ($after < 0) {
            throw new RuntimeException('insufficient_points');
        }

        $account->update(['balance' => $after]);

        return PointTransaction::create($extra + [
            'user_id' => $account->user_id,
            'direction' => $direction,
            'points' => $points,
            'reason' => $reason,
            'balance_after' => $after,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'dedupe_key' => $key,
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * When returned points lapse: as their lot does, unless it already has.
     */
    private function expiryOnReturn(?Carbon $lotExpiry, ?Carbon $spentAt): ?Carbon
    {
        if ($lotExpiry === null) {
            return null;
        }

        // The clock stops while an order holds the points: they come back with
        // the time they had left when they were spent, to the end of that
        // business day — and never with less than their lot had. Not reset: a
        // fresh lifetime would let a customer renew points about to lapse by
        // spending them on an order and cancelling it, as often as they liked.
        // Not left running either: points handed back an hour before their
        // lot's end would lapse before anybody could use them.
        $left = $spentAt !== null && $spentAt->lessThan($lotExpiry)
            ? (int) $spentAt->diffInSeconds($lotExpiry, true)
            : 0;

        $resumed = now()->addSeconds($left)
            ->setTimezone(displayTimezone())
            ->endOfDay()
            ->utc();

        return $resumed->greaterThan($lotExpiry) ? $resumed : $lotExpiry->copy();
    }

    /**
     * The business day an instant falls on, for comparing expiries.
     */
    private function businessDay(Carbon $instant): string
    {
        return $instant->copy()->setTimezone(displayTimezone())->toDateString();
    }

    /**
     * Sort key: later is larger, and «never» is largest of all.
     */
    private function expiryRank(?Carbon $expiry): int
    {
        return $expiry === null ? PHP_INT_MAX : $expiry->getTimestamp();
    }
}
