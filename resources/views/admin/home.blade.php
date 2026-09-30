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
                            ? route($item['route'], $item['params'] ?? [])
                            : null;
                    @endphp
                    <div class="home-queue-row {{ $severityClass[$item['severity']] ?? '' }}">
                        <div class="home-queue-count">{{ $item['count'] }}</div>
                        <div class="home-queue-text">
                            <strong>{{ __($item['label']) }}</strong>
                            {{-- Why it matters, not what it is. A count with no
                                 consequence attached gets ignored. --}}
                            <small class="text-muted d-block">{{ __($item['hint'], $item['hintParams'] ?? []) }}</small>
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

        @include('admin.partials._viz')

        @php
            // The stages of a live order, in the order they happen — so they
            // take one blue from light to dark (`--viz-o1..5`), not five hues.
            $stages = [
                'awaiting_pickup' => [__('Booked, not collected'), 'bi-clock', '--viz-o1'],
                'with_driver' => [__('With a driver'), 'bi-truck', '--viz-o2'],
                'at_laundry' => [__('At a laundry'), 'bi-droplet', '--viz-o3'],
                'ready_to_go' => [__('Ready to go out'), 'bi-box-seam', '--viz-o4'],
                'delivered_unpaid' => [__('Delivered, unpaid'), 'bi-cash-coin', '--viz-o5'],
            ];
            $liveTotal = array_sum($inFlight);
            $liveSlices = collect($stages)
                ->map(fn ($stage, $key) => ['label' => $stage[0], 'value' => (int) $inFlight[$key], 'color' => $stage[2]])
                ->filter(fn ($slice) => $slice['value'] > 0)
                ->values();

            $serviceSlices = collect($byService['items'])
                ->map(fn ($item) => ['label' => $item['label'], 'value' => $item['count'], 'color' => '--viz-s'.$item['slot']]);
            if ($byService['other'] > 0) {
                $serviceSlices->push(['label' => __('Other services'), 'value' => $byService['other'], 'color' => '--viz-other']);
            }

            $dayLabels = collect($byDay)->map(fn ($day) => \Illuminate\Support\Carbon::parse($day['date'])->format('j/n'))->all();
            $daySeries = [
                ['name' => __('Orders placed'), 'values' => array_column($byDay, 'placed'), 'color' => '--viz-s1'],
                ['name' => __('Delivered'), 'values' => array_column($byDay, 'delivered'), 'color' => '--viz-s2'],
                ['name' => __('Cancelled'), 'values' => array_column($byDay, 'cancelled'), 'color' => '--viz-s3'],
            ];
            $dayTotal = array_sum(array_map(fn ($day) => $day['placed'] + $day['delivered'] + $day['cancelled'], $byDay));
            $ratingsTotal = array_sum($ratings);
            $ratingsTop = max($ratings ?: [0]);
        @endphp

        <div class="row">
            {{-- Where every live order physically is. A ring of parts, and
                 beside it the legend — which is also the table: every stage
                 with its count and its icon, so nothing hangs on the colour. --}}
            <div class="col-lg-5 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Right now') }}</h6>
                        <small class="text-muted">{{ __('Every order that has not finished') }}</small>
                    </div>
                    <div class="card-body">
                        @if ($liveTotal === 0)
                            <p class="viz-empty">{{ __('No order is under way.') }}</p>
                        @else
                            <div class="row g-3 align-items-center">
                                {{-- A ring of one or two slices says less than
                                     the numbers do, so it is drawn from three. --}}
                                @if ($liveSlices->count() >= 3)
                                    <div class="col-sm-6">
                                        <div id="viz-stages" class="viz-canvas" dir="ltr"></div>
                                    </div>
                                @endif
                                <div class="{{ $liveSlices->count() >= 3 ? 'col-sm-6' : 'col-12' }}">
                                    <ul class="viz-legend">
                                        @foreach ($stages as $key => [$label, $icon, $color])
                                            <li>
                                                <span class="viz-swatch" style="background: var({{ $color }})"></span>
                                                <span><i class="bi {{ $icon }} me-1"></i>{{ $label }}</span>
                                                <span class="viz-value">{{ $inFlight[$key] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Orders per day. Lines, one crosshair; the legend names them
                 and the table under it holds every number. --}}
            <div class="col-lg-7 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Orders per day') }}</h6>
                        <small class="text-muted">{{ __('The last 14 days') }}</small>
                    </div>
                    <div class="card-body">
                        @if ($dayTotal === 0)
                            {{-- A flat line along zero says less than this does. --}}
                            <p class="viz-empty">{{ __('Nothing in the last 14 days.') }}</p>
                        @else
                        <ul class="viz-legend-row">
                            @foreach ($daySeries as $series)
                                <li><span class="viz-key" style="background: var({{ $series['color'] }})"></span>{{ $series['name'] }}</li>
                            @endforeach
                        </ul>
                        <div id="viz-days" class="viz-canvas" dir="ltr"></div>
                        <details class="viz-table">
                            <summary>{{ __('Show the numbers') }}</summary>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Date') }}</th>
                                            @foreach ($daySeries as $series)
                                                <th class="text-end">{{ $series['name'] }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($byDay as $day)
                                            <tr>
                                                <td>{{ $day['date'] }}</td>
                                                <td class="text-end">{{ $day['placed'] }}</td>
                                                <td class="text-end">{{ $day['delivered'] }}</td>
                                                <td class="text-end">{{ $day['cancelled'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                        @endif
                    </div>
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
                            {{-- Counts, never money: the money is on
                                 «ملخص الماليات», which has a permission of its
                                 own. This page has none. --}}
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ $today['picked_up'] }}</span>
                                    <span class="home-stat-label">{{ __('Picked up from customers') }}</span>
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
                        @if (!$isLaundry && Route::has('admin.report.orders') && canDo('report.view'))
                            <a href="{{ route('admin.report.orders') }}" class="small">{{ __('Reports') }}</a>
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
                                        <span class="home-stat-num">{{ $month['completed'] }}</span>
                                        <span class="home-stat-label">{{ __('Completed') }}</span>
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
                                        <span class="home-stat-num">{{ $month['placed'] }}</span>
                                        <span class="home-stat-label">{{ __('Orders placed') }}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="home-stat">
                                        <span class="home-stat-num">{{ $month['completed'] }}</span>
                                        <span class="home-stat-label">{{ __('Completed') }}</span>
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

        <div class="row">
            {{-- This month by service: the five busiest, the rest as one grey
                 slice. Each service keeps its colour whatever its rank. --}}
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('By service') }}</h6>
                        <small class="text-muted">{{ __('Orders this month') }}</small>
                    </div>
                    <div class="card-body">
                        @if ($byService['total'] === 0)
                            <p class="viz-empty">{{ __('No orders yet this month.') }}</p>
                        @else
                            <div class="row g-3 align-items-center">
                                @if ($serviceSlices->count() >= 3)
                                    <div class="col-sm-6">
                                        <div id="viz-services" class="viz-canvas" dir="ltr"></div>
                                    </div>
                                @endif
                                <div class="{{ $serviceSlices->count() >= 3 ? 'col-sm-6' : 'col-12' }}">
                                    <ul class="viz-legend">
                                        @foreach ($serviceSlices as $slice)
                                            <li>
                                                <span class="viz-swatch" style="background: var({{ $slice['color'] }})"></span>
                                                <span>{{ $slice['label'] }}</span>
                                                <span class="viz-value">
                                                    {{ $slice['value'] }}<span class="viz-share">{{ round($slice['value'] / $byService['total'] * 100) }}%</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- The spread an average hides: how many at each score. --}}
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Ratings') }}</h6>
                        <small class="text-muted">{{ __('From the 1st to today') }}</small>
                    </div>
                    <div class="card-body">
                        @if ($ratingsTotal === 0)
                            <p class="viz-empty">{{ __('No ratings yet this month.') }}</p>
                        @else
                            <div class="viz-bars" role="list">
                                @foreach ($ratings as $score => $count)
                                    <div class="viz-bar-row" role="listitem">
                                        <span class="viz-bar-label" dir="ltr">{{ $score }} ★</span>
                                        <span class="viz-bar-track">
                                            <span class="viz-bar-fill d-block"
                                                style="width: {{ $ratingsTop > 0 ? round($count / $ratingsTop * 100, 1) : 0 }}%"></span>
                                        </span>
                                        <span class="viz-bar-value">{{ $count }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
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

    {{-- The charts' data, as JSON the page cannot be broken out of (`@json`
         escapes <, >, & and quotes), read back by `laundoCharts.data()`.
         One variable each: `@json([...])` with an array literal is split on
         its commas by Blade and loses those flags. --}}
    @php($dayData = ['labels' => $dayLabels, 'series' => $daySeries])
    <script type="application/json" id="viz-data-stages">@json($liveSlices)</script>
    <script type="application/json" id="viz-data-services">@json($serviceSlices)</script>
    <script type="application/json" id="viz-data-days">@json($dayData)</script>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var charts = window.laundoCharts;
            if (!charts) return;

            var total = @json(__('Total'));
            charts.donut(document.getElementById('viz-stages'), charts.data('viz-data-stages'), total);
            charts.donut(document.getElementById('viz-services'), charts.data('viz-data-services'), total);

            var days = charts.data('viz-data-days');
            charts.lines(document.getElementById('viz-days'), days.labels, days.series);
        });
    </script>
@endpush
