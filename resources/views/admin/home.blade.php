@extends('layouts.main')

@section('content')
    @php
        $severityClass = [
            'critical' => 'home-sev-critical',
            'warning' => 'home-sev-warning',
            'info' => 'home-sev-info',
        ];
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">
            {{ __('Good day') }}, {{ auth()->user()->name }}
        </h5>
        <span class="text-muted small">
            {{ __('As of') }} {{ humanDate(now(), 'Y-m-d H:i') }}
        </span>
    </div>

    <section class="section">

        {{--
            The queue comes first, above everything.

            The old page opened with total customers and total banners. Neither
            changed during a working day and neither was actionable. What a person
            opening this screen needs is the list of things waiting for them — so
            it is the first thing, and it is empty when there is nothing to do.
        --}}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    {{ $isLaundry ? __('Your work, in order') : __('Waiting for a person') }}
                </h6>
                @if (count($queue) > 0)
                    <span class="badge bg-danger">{{ count($queue) }}</span>
                @endif
            </div>
            <div class="card-body">
                @forelse ($queue as $item)
                    @php
                        $target = ($item['route'] ?? null) && Route::has($item['route'])
                            ? route($item['route'])
                            : null;
                    @endphp
                    <div class="home-queue-row {{ $severityClass[$item['severity']] ?? '' }}">
                        <div class="home-queue-count">{{ $item['count'] }}</div>
                        <div class="home-queue-text">
                            <strong>{{ __($item['label']) }}</strong>
                            {{-- Why it matters, not what it is. A count with no
                                 consequence attached gets ignored. --}}
                            <small class="text-muted d-block">{{ __($item['hint']) }}</small>
                        </div>
                        @if ($target)
                            <a href="{{ $target }}" class="btn btn-sm btn-outline-primary">
                                {{ __('Open') }}
                            </a>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-3">
                        <strong class="d-block">{{ __('Nothing is waiting.') }}</strong>
                        <small class="text-muted">
                            {{ $isLaundry
                                ? __('No orders need you right now.')
                                : __('No order, journey, refund or complaint needs a decision.') }}
                        </small>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Where every live order physically is. --}}
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Right now') }}</h6>
                <small class="text-muted">{{ __('Every order that has not finished') }}</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ([
                        'awaiting_pickup' => [__('Booked, not collected'), 'bi-clock'],
                        'with_driver' => [__('With a driver'), 'bi-truck'],
                        'at_laundry' => [__('At a laundry'), 'bi-droplet'],
                        'ready_to_go' => [__('Ready to go out'), 'bi-box-seam'],
                        'delivered_unpaid' => [__('Delivered, unpaid'), 'bi-cash-coin'],
                    ] as $key => [$label, $icon])
                        <div class="col-6 col-md">
                            {{-- Icon and figure share a line, label sits under
                                 them: the tile is read as «this many, here»,
                                 and a stacked icon only pushed the number down
                                 the tile away from its own label. --}}
                            <div class="home-flight">
                                <div class="home-flight-head">
                                    <i class="bi {{ $icon }}"></i>
                                    <span class="home-flight-num">{{ $inFlight[$key] }}</span>
                                </div>
                                <span class="home-flight-label">{{ $label }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row">
            {{-- Today --}}
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Since midnight') }}</h6>
                        {{-- Today, not "last 24 hours": operations works in days,
                             and a rolling window makes two people looking at the
                             same screen disagree. --}}
                        <small class="text-muted">{{ __('Today, not the last 24 hours') }}</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ $today['orders_placed'] }}</span>
                                    <span class="home-stat-label">{{ __('Orders placed') }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ moneyFormat($today['money_taken']) }}</span>
                                    <span class="home-stat-label">{{ __('Money taken') }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ $today['delivered'] }}</span>
                                    <span class="home-stat-label">{{ __('Delivered') }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num {{ $today['cancelled'] > 0 ? 'text-danger' : '' }}">
                                        {{ $today['cancelled'] }}
                                    </span>
                                    <span class="home-stat-label">{{ __('Cancelled') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The month --}}
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    {{-- The window is spelled out for the same reason its
                         neighbour's is, and so the two headers are the same
                         height: a one-line header beside a two-line one put
                         the two cards' figures 21px out of line with each
                         other, and a row of paired cards is read across. --}}
                    <div class="card-header d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-0">{{ __('This month so far') }}</h6>
                            <small class="text-muted">{{ __('From the 1st to today') }}</small>
                        </div>
                        @if (!$isLaundry && Route::has('admin.report.revenue') && canDo('report.view'))
                            <a href="{{ route('admin.report.revenue') }}" class="small">{{ __('Reports') }}</a>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @if ($isLaundry)
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num">{{ $month['orders'] }}</span>
                                        <span class="home-stat-label">{{ __('Orders received') }}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num">{{ moneyFormat($month['revenue']) }}</span>
                                        <span class="home-stat-label">{{ __('Paid to date') }}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num {{ $month['disputed'] > 0 ? 'text-attention' : '' }}">
                                            {{ $month['disputed'] }}
                                        </span>
                                        <span class="home-stat-label">{{ __('Counts questioned') }}</span>
                                    </div>
                                </div>
                            @else
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num">{{ moneyFormat($month['net_revenue']) }}</span>
                                        <span class="home-stat-label">{{ __('Net revenue') }}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num {{ $month['receivables'] > 0 ? 'text-attention' : '' }}">
                                            {{ moneyFormat($month['receivables']) }}
                                        </span>
                                        {{-- Owed, not earned. Never inside revenue. --}}
                                        <span class="home-stat-label">{{ __('Owed to us') }}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num {{ $month['cancellation_rate'] > 10 ? 'text-danger' : '' }}">
                                            {{ $month['cancellation_rate'] }}%
                                        </span>
                                        <span class="home-stat-label">{{ __('Cancellation rate') }}</span>
                                    </div>
                                </div>
                            @endif

                            <div class="col-6">
                                <div class="home-stat">
                                    @if ($month['average_rating'] === null)
                                        {{-- Unrated and badly rated are different claims. --}}
                                        <span class="home-stat-num text-muted">—</span>
                                        <span class="home-stat-label">{{ __('Not rated yet') }}</span>
                                    @else
                                        <span class="home-stat-num {{ $month['average_rating'] < 3.5 ? 'text-attention' : '' }}">
                                            {{ $month['average_rating'] }}<small class="text-muted">/5</small>
                                        </span>
                                        <span class="home-stat-label">
                                            {{ __('Rating') }}
                                            @if ($month['unhappy'] > 0)
                                                · <span class="text-danger">
                                                    {{ $month['unhappy'] }} {{ __('unhappy') }}
                                                </span>
                                            @endif
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if (!$isLaundry)
            <div class="row">
                {{-- Drivers. Tasks carry no laundry_id, so this is platform-only. --}}
                <div class="col-md-4 mb-3">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0">{{ __('Drivers') }}</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-baseline gap-2 mb-2">
                                <span class="home-stat-num">{{ $drivers['idle'] }}</span>
                                <span class="text-muted">/ {{ $drivers['total'] }} {{ __('free') }}</span>
                            </div>
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="text-muted p-0 small">{{ __('Carrying an order') }}</td>
                                        <td class="text-end p-0"><strong>{{ $drivers['busy'] }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted p-0 small">{{ __('Open journeys') }}</td>
                                        <td class="text-end p-0"><strong>{{ $drivers['open_journeys'] }}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- The orders somebody should look at first. --}}
                <div class="col-md-8 mb-3">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">{{ __('Look at these first') }}</h6>
                            {{-- Oldest first. Newest-first hides the order stuck
                                 since Tuesday behind one placed a minute ago. --}}
                            <small class="text-muted">{{ __('Oldest problem first') }}</small>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless mb-0">
                                    <tbody>
                                        @forelse ($attention as $order)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('admin.order.show', $order->id) }}">
                                                        <strong>{{ $order->code }}</strong>
                                                    </a>
                                                    <small class="text-muted d-block">
                                                        {{ $order->customer?->name }}
                                                    </small>
                                                </td>
                                                <td>
                                                    @if ($order->laundry)
                                                        <small>{{ getLocalizedValueDashboard($order->laundry, 'name') }}</small>
                                                    @else
                                                        <span class="badge bg-danger">{{ __('No laundry') }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <small>{{ __($order->status->label()) }}</small>
                                                </td>
                                                <td class="text-end">
                                                    <small class="text-muted">
                                                        {{ $order->created_at?->diffForHumans() }}
                                                    </small>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                {{-- Four columns above, so the
                                                     empty state has to span
                                                     four or it centres itself
                                                     inside the first one. --}}
                                                <td colspan="4" class="text-center text-muted py-3">
                                                    {{ __('Every order is moving.') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection
