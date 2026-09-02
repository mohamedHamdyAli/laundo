{{--
    Direction C, «Stack». Each order is a row-card, not a table row.

    The whole card is the link: `admin.order.index` and `admin.order.show` both
    sit behind `permission:order.view`, so anybody reading this list can open
    any row in it and there is no second action to reserve a column for.

    It still needs to *look* openable. This was the only list of the 26 with no
    actions cell, and «the whole row is a link» is invisible: a reader saw five
    columns of text and nothing saying the details were one click away. The
    chevron on the trailing edge is that affordance. It is a plain glyph inside
    the anchor rather than a button or a second link — a control nested in a
    control is invalid, unreachable by keyboard, and would give the row two tab
    stops to the same page.

    The file keeps its `_table_body` name because OrderController@search renders
    it by that path, and the AJAX helper replaces the container's HTML wholesale
    — a div container takes card markup exactly as a tbody took rows.
--}}
@forelse ($orders as $order)
    <a href="{{ route('admin.order.show', $order->id) }}"
        class="stack-row tone-{{ $order->status->tone() }}">

        <div>
            <span class="row-lead">#{{ $order->code }}</span>
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

        {{-- Mirrored in RTL from the stylesheet, so there is one icon name
             here rather than a direction test in the markup. --}}
        <div class="stack-open" aria-hidden="true">
            <i class="bi bi-chevron-right"></i>
        </div>

    </a>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
