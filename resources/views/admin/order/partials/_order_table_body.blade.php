{{--
    Direction C, «Stack». Each order is a row-card, not a table row.

    **Shape 2, not shape 1.** This list used to be the one place shape 1 was
    used — the row itself an `<a>`, the whole card the link — because `index`
    and `show` sat behind the same permission and there was no second action to
    reserve a column for. There is one now: `order.delete`. A link cannot
    contain a button, so the row becomes a `<div>`, the code carries the link,
    and the button sits in `.stack-actions`. See the shape note in theme.css.

    The delete is offered on the **status** half of `OrderDeletionGuard` only.
    Asking the whole guard here would be five existence queries per row; the
    money half runs when the button is actually pressed, and a refusal comes
    back naming the rule that caught it.

    The file keeps its `_table_body` name because OrderController@search renders
    it by that path, and the AJAX helper replaces the container's HTML wholesale
    — a div container takes card markup exactly as a tbody took rows.
--}}
@php
    $deletionGuard = app(\App\Modules\Order\Services\OrderDeletionGuard::class);
@endphp

@forelse ($orders as $order)
    <div class="stack-row tone-{{ $order->status->tone() }}">

        <div>
            <span class="row-lead">
                <a href="{{ route('admin.order.show', $order->id) }}">#{{ $order->code }}</a>
            </span>
            <span class="row-sub">{{ humanDate($order->created_at) }}</span>
        </div>

        <div>
            <span class="row-main">{{ $order->customer?->name ?? '-' }}</span>
            <span class="row-sub">{{ $order->customer?->phone }}</span>
        </div>

        <div>
            <span class="row-main">
                {{ $order->service ? getLocalizedValueDashboard($order->service, 'name') : '-' }}
            </span>
            <span class="row-sub">
                @if ($order->laundry)
                    {{ getLocalizedValueDashboard($order->laundry, 'name') }}
                @else
                    {{-- Accepted unassigned by decision: nothing covered the zone,
                         and an operator places it rather than the customer being
                         refused. --}}
                    {{ __('Unassigned') }}
                @endif
            </span>
        </div>

        <div>
            <span class="status-pill tone-{{ $order->status->tone() }}">
                {{ __($order->status->label()) }}
            </span>
            {{-- The pickup date belongs with the status: together they say what
                 is happening and when. --}}
            <span class="row-sub">
                {{ $order->pickup_date
                    ? __('Pickup') . ' ' . humanDate($order->pickup_date, 'Y-m-d')
                    : __('No pickup date') }}
            </span>
        </div>

        <div class="row-amount">
            {{ moneyFormat($order->payableTotal()) }}
            @if ($order->final_total !== null)
                <span class="row-sub">{{ __('Final') }}</span>
            @endif
        </div>

        <div class="stack-actions">
            {{-- The chevron stays. It is the affordance that says the row opens,
                 and it is the same link as the code — one destination, and it
                 keeps its own tab stop off the page by being aria-hidden with
                 the code carrying the accessible name. --}}
            <a href="{{ route('admin.order.show', $order->id) }}" class="btn btn-sm action-btn action-view"
                tabindex="-1" aria-hidden="true">
                <i class="bi bi-chevron-right"></i>
            </a>
            @include('admin.order.shared.controlBut', [
                'row' => $order,
                'offer' => $deletionGuard->statusAllows($order),
            ])
        </div>

    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
