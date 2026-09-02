{{--
    Direction C, «Stack». Each order is a row-card, not a table row.

    The whole card is the link: `admin.order.index` and `admin.order.show` both
    sit behind `permission:order.view`, so anybody reading this list can open
    any row in it and there is no second action to reserve a column for.

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

    </a>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
