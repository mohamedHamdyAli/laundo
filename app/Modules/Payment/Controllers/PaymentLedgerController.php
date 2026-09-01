<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Money in and money out.
 *
 * Two screens that were only ever reachable one order at a time. Payments could
 * be seen inside an order and nowhere else, so nobody could reconcile a day's
 * takings against the gateway without querying the database; and driver earnings
 * existed solely in the driver's own app, so operations could not say what a
 * driver was owed.
 *
 * Deliberately **not** tenant-scoped, and neither `Payment` nor `DriverEarning`
 * uses `BelongsToLaundry`. A payment is the platform collecting money and an
 * earning is the platform owing it; a laundry is party to neither. The protection
 * is that both screens are gated on permissions granted to the super admin alone.
 *
 * The figure each screen leads with is the one nobody could get before: for
 * payments, what is stuck rather than what succeeded; for earnings, what is owed
 * rather than what has been paid.
 */
class PaymentLedgerController extends Controller
{
    // ------------------------------------------------------------- payments

    public function payments(Request $request)
    {
        $status = (string) $request->get('status', 'all');

        $view = view('admin.payment.index', [
            'payments' => $this->paymentQuery($status)->paginate(20),
            'status' => $status,
            'statuses' => PaymentStatus::cases(),
            'summary' => $this->paymentSummary(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function searchPayments(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $payments = $this->paymentQuery((string) $request->get('status', 'all'))
            ->when($term !== '', function (Builder $q) use ($term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('provider_reference', 'like', "%{$term}%")
                        ->orWhereHas('order', fn ($o) => $o->where('code', 'like', "%{$term}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->paginate(20);

        return response()->json([
            'table' => view('admin.payment.partials._payment_table_body', compact('payments'))->render(),
            'pagination' => $payments->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * @return Builder<Payment>
     */
    private function paymentQuery(string $status): Builder
    {
        return Payment::with(['order:id,code', 'customer:id,name,phone'])
            ->when(
                in_array($status, array_column(PaymentStatus::cases(), 'value'), true),
                fn (Builder $q) => $q->where('status', $status)
            )
            // Stuck first. A payment that has been pending for a day is the only
            // kind anybody needs to act on, and "newest first" buries it.
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', [
                PaymentStatus::Pending->value,
                PaymentStatus::Authorised->value,
            ])
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSummary(): array
    {
        $today = Payment::whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
        $month = Payment::whereBetween('created_at', [now()->startOfMonth(), now()->endOfDay()]);

        return [
            'captured_today' => (float) (clone $today)->where('status', PaymentStatus::Captured->value)->sum('amount'),
            'captured_month' => (float) (clone $month)->where('status', PaymentStatus::Captured->value)->sum('amount'),

            // Authorised and never captured. Money the customer believes they have
            // paid and we have not taken — the single most expensive state to leave
            // unnoticed, because the authorisation expires.
            'authorised_uncaptured' => Payment::where('status', PaymentStatus::Authorised->value)->count(),

            // Pending for more than an hour. A gateway round trip takes seconds;
            // anything still pending is a redirect the customer abandoned or a
            // webhook that never arrived.
            'stuck' => Payment::where('status', PaymentStatus::Pending->value)
                ->where('created_at', '<', now()->subHour())
                ->count(),

            'failed_today' => (clone $today)->where('status', PaymentStatus::Failed->value)->count(),
        ];
    }

    // ------------------------------------------------------------- earnings

    public function earnings(Request $request)
    {
        $status = (string) $request->get('status', 'pending');

        $view = view('admin.earning.index', [
            'earnings' => $this->earningQuery($status)->paginate(20),
            'status' => $status,
            'summary' => $this->earningSummary(),
            'byDriver' => $this->owedByDriver(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function searchEarnings(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $earnings = $this->earningQuery((string) $request->get('status', 'pending'))
            ->when($term !== '', fn (Builder $q) => $q->whereHas(
                'payee',
                fn ($d) => $d->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")
            ))
            ->paginate(20);

        return response()->json([
            'table' => view('admin.earning.partials._earning_table_body', compact('earnings'))->render(),
            'pagination' => $earnings->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * @return Builder<DriverEarning>
     */
    private function earningQuery(string $status): Builder
    {
        return DriverEarning::with(['payee:id,name,phone', 'order:id,code', 'task:id,type'])
            ->when(
                in_array($status, [DriverEarning::PENDING, DriverEarning::RELEASED, DriverEarning::CANCELLED], true),
                fn (Builder $q) => $q->where('status', $status)
            )
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function earningSummary(): array
    {
        return [
            // «الرصيد المعلق» — earned on a completed leg, not yet withdrawable
            // because the order can still be returned.
            'pending' => (float) DriverEarning::pending()->sum('amount'),
            'released' => (float) DriverEarning::released()->sum('amount'),
            'released_month' => (float) DriverEarning::released()
                ->whereBetween('released_at', [now()->startOfMonth(), now()->endOfDay()])
                ->sum('amount'),
            'drivers_owed' => DriverEarning::pending()->distinct('driver_id')->count('driver_id'),
        ];
    }

    /**
     * What each driver is owed right now.
     *
     * The question operations actually has, and the reason this screen exists: a
     * driver asking "when am I paid" was previously answerable only from the
     * driver's own app.
     *
     * @return array<int, array<string, mixed>>
     */
    private function owedByDriver(): array
    {
        return DriverEarning::pending()
            ->with('payee:id,name,phone')
            ->selectRaw('driver_id, count(*) as legs, sum(amount) as owed')
            ->groupBy('driver_id')
            ->orderByDesc('owed')
            ->get()
            ->map(fn (DriverEarning $row) => [
                'driver' => $row->payee->name,
                'phone' => $row->payee->phone,
                'legs' => (int) $row->getAttribute('legs'),
                'owed' => round((float) $row->getAttribute('owed'), 2),
            ])
            ->all();
    }
}
