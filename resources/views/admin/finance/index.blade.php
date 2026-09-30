@extends('layouts.main')

@section('content')
    {{--
        «ملخص الماليات» — the money the home page used to show, and more of it.

        The home page has no permission: every panel account opens it. This one
        is behind `finance.view`, which nobody holds until somebody grants it.
        Every revenue figure is `RevenueReport`'s, dated by `paid_at`, so it
        agrees with the revenue report to the piastre; the split is the
        settlements' own columns.

        A laundry granted the page reads its own orders' money (the models are
        scoped) and is not shown the drivers' figure or the laundry ranking.
    --}}
    @include('admin.partials._viz')

    @php
        $dayLabels = collect($byDay)->map(fn ($day) => \Illuminate\Support\Carbon::parse($day['date'])->format('j/n'))->all();
        $dayValues = array_column($byDay, 'total');
        $dayMoney = array_map(fn ($total) => moneyFormat($total), $dayValues);

        // The washing is the one thing divided: the two halves of the bar.
        $washing = max($split['laundries'], 0) + max($split['platform'], 0);
        $share = fn (float $part) => $washing > 0 ? round(max($part, 0) / $washing * 100, 1) : 0;

        $methodSlices = collect($byMethod)->map(fn ($row) => [
            'label' => $row['label'], 'value' => $row['total'], 'color' => '--viz-s'.min($row['slot'], 8),
        ]);
        $methodTotal = array_sum(array_column($byMethod, 'total'));

        $kpis = [
            ['key' => 'gross', 'label' => __('Money taken'), 'money' => true],
            ['key' => 'net', 'label' => __('Net revenue'), 'money' => true],
            ['key' => 'orders', 'label' => __('Paid orders'), 'money' => false],
            ['key' => 'average_order', 'label' => __('Average order'), 'money' => true],
        ];
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Finance overview') }}</h5>
        <span class="text-muted small">{{ __('As of') }} {{ humanDate(now(), 'Y-m-d H:i') }}</span>
    </div>

    <section class="section">
        {{-- The month so far, each figure against the same days last month. --}}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-0">{{ __('This month so far') }}</h6>
                    <small class="text-muted">{{ __('Compared with the same days last month') }}</small>
                </div>
                @if (Route::has('admin.report.revenue') && canDo('report.view'))
                    <a href="{{ route('admin.report.revenue') }}" class="small">{{ __('Reports') }}</a>
                @endif
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($kpis as $kpi)
                        @php $figure = $compared[$kpi['key']]; @endphp
                        <div class="col-6 col-lg-3">
                            <div class="home-stat">
                                <span class="home-stat-num">
                                    {{ $kpi['money'] ? moneyFormat($figure['now']) : $figure['now'] }}
                                </span>
                                <span class="home-stat-label">{{ $kpi['label'] }}</span>
                                @if ($figure['change'] === null)
                                    <span class="viz-delta">{{ __('Nothing last month to compare with') }}</span>
                                @else
                                    <span class="viz-delta {{ $figure['change'] > 0 ? 'is-up' : ($figure['change'] < 0 ? 'is-down' : '') }}">
                                        <b dir="ltr">{{ $figure['change'] > 0 ? '▲ +' : ($figure['change'] < 0 ? '▼ ' : '') }}{{ $figure['change'] }}%</b>
                                        {{ __('against') }}
                                        {{ $kpi['money'] ? moneyFormat($figure['before']) : $figure['before'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-5 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Since midnight') }}</h6>
                        <small class="text-muted">{{ __('Today, not the last 24 hours') }}</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ moneyFormat($today['money_taken']) }}</span>
                                    <span class="home-stat-label">{{ __('Money taken') }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ $today['paid_orders'] }}</span>
                                    <span class="home-stat-label">{{ __('Paid orders') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Money not yet where it is going. --}}
            <div class="col-md-7 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Still to move') }}</h6>
                        <small class="text-muted">{{ __('Owed to us, and owed by us') }}</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-lg">
                                <div class="home-stat">
                                    <span class="home-stat-num {{ $owed['receivables'] > 0 ? 'text-attention' : '' }}">
                                        {{ moneyFormat($owed['receivables']) }}
                                    </span>
                                    {{-- Owed, not earned. Never inside revenue. --}}
                                    <span class="home-stat-label">{{ __('Owed to us') }}</span>
                                </div>
                            </div>
                            <div class="col-6 col-lg">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ moneyFormat($owed['settlements']['amount']) }}</span>
                                    <span class="home-stat-label">
                                        {{ __('Laundries\' share, not settled yet') }}
                                        <small class="d-block">{{ trans_choice(':count order|:count orders', $owed['settlements']['count']) }}</small>
                                    </span>
                                </div>
                            </div>
                            @if ($owed['drivers'] !== null)
                                <div class="col-6 col-lg">
                                    <div class="home-stat">
                                        <span class="home-stat-num">{{ moneyFormat($owed['drivers']) }}</span>
                                        <span class="home-stat-label">{{ __('Driver bonuses not released yet') }}</span>
                                    </div>
                                </div>
                            @endif
                            <div class="col-6 col-lg">
                                <div class="home-stat">
                                    <span class="home-stat-num">{{ moneyFormat($month['refunds']) }}</span>
                                    <span class="home-stat-label">{{ __('Refunded this month') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Money taken per day: one series, so no legend — the title names
             it — and the table under it holds every figure. --}}
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Money taken per day') }}</h6>
                <small class="text-muted">{{ __('The last 14 days') }}</small>
            </div>
            <div class="card-body">
                @if (array_sum($dayValues) == 0)
                    <p class="viz-empty">{{ __('Nothing in the last 14 days.') }}</p>
                @else
                <div id="viz-money" class="viz-canvas" dir="ltr"></div>
                <details class="viz-table">
                    <summary>{{ __('Show the numbers') }}</summary>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Money taken') }}</th>
                                    <th class="text-end">{{ __('Paid orders') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($byDay as $day)
                                    <tr>
                                        <td>{{ $day['date'] }}</td>
                                        <td class="text-end">{{ moneyFormat($day['total']) }}</td>
                                        <td class="text-end">{{ $day['orders'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
                @endif
            </div>
        </div>

        <div class="row">
            {{-- Who keeps what: the washing divided, and what sits beside it. --}}
            <div class="col-lg-7 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('Who keeps what') }}</h6>
                        <small class="text-muted">
                            {{ __('Orders settled this month') }} · {{ $split['orders'] }}
                        </small>
                    </div>
                    <div class="card-body">
                        @if ($split['orders'] === 0)
                            <p class="viz-empty">{{ __('No order was settled this month yet.') }}</p>
                        @else
                            <p class="small text-muted mb-1">{{ __('The washing, divided') }}</p>
                            @if ($washing > 0)
                                <div class="viz-stack" role="img"
                                    aria-label="{{ __('Laundries') }} {{ $share($split['laundries']) }}%, {{ __('The platform') }} {{ $share($split['platform']) }}%">
                                    @if ($split['laundries'] > 0)
                                        <span style="width: {{ $share($split['laundries']) }}%; background: var(--viz-s1)"></span>
                                    @endif
                                    @if ($split['platform'] > 0)
                                        <span style="width: {{ $share($split['platform']) }}%; background: var(--viz-s2)"></span>
                                    @endif
                                </div>
                            @endif
                            <ul class="viz-legend mb-3">
                                <li>
                                    <span class="viz-swatch" style="background: var(--viz-s1)"></span>
                                    <span>{{ __('Laundries') }}</span>
                                    <span class="viz-value">{{ moneyFormat($split['laundries']) }}<span class="viz-share">{{ $share($split['laundries']) }}%</span></span>
                                </li>
                                <li>
                                    <span class="viz-swatch" style="background: var(--viz-s2)"></span>
                                    <span>
                                        {{ __('The platform') }}
                                        @if ($split['platform'] < 0)
                                            {{-- A coupon the platform pays for can cost it more
                                                 than its part of the washing. --}}
                                            <small class="d-block">{{ __('Discounts the platform paid for cost more than its part') }}</small>
                                        @endif
                                    </span>
                                    <span class="viz-value">{{ moneyFormat($split['platform']) }}<span class="viz-share">{{ $share($split['platform']) }}%</span></span>
                                </li>
                            </ul>

                            <p class="small text-muted mb-1">{{ __('Beside the washing') }}</p>
                            <ul class="viz-legend">
                                <li><span></span><span>{{ __('Delivery fees') }}</span><span class="viz-value">{{ moneyFormat($split['delivery_fees']) }}</span></li>
                                <li><span></span><span>{{ __('Customer platform fee') }}</span><span class="viz-value">{{ moneyFormat($split['platform_fees']) }}</span></li>
                                <li><span></span><span>{{ __('Discounts given') }}</span><span class="viz-value">{{ moneyFormat($split['discounts']) }}</span></li>
                                <li><span></span><span>{{ __('Tax') }}</span><span class="viz-value">{{ moneyFormat($split['tax']) }}</span></li>
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            {{-- How customers paid. --}}
            <div class="col-lg-5 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __('By payment method') }}</h6>
                        <small class="text-muted">{{ __('From the 1st to today') }}</small>
                    </div>
                    <div class="card-body">
                        @if ($methodTotal == 0)
                            <p class="viz-empty">{{ __('Nothing paid yet this month.') }}</p>
                        @else
                            {{-- A ring of one or two slices says less than the
                                 numbers do, so it is drawn from three. --}}
                            @if ($methodSlices->count() >= 3)
                                <div id="viz-methods" class="viz-canvas mb-2" dir="ltr"></div>
                            @endif
                            <ul class="viz-legend">
                                @foreach ($byMethod as $row)
                                    <li>
                                        <span class="viz-swatch" style="background: var(--viz-s{{ min($row['slot'], 8) }})"></span>
                                        <span>{{ $row['label'] }} <small>· {{ trans_choice(':count order|:count orders', $row['orders']) }}</small></span>
                                        <span class="viz-value">{{ moneyFormat($row['total']) }}<span class="viz-share">{{ round($row['total'] / $methodTotal * 100) }}%</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            @foreach (array_filter([
                ['title' => __('By service'), 'data' => $byService],
                $byLaundry !== null ? ['title' => __('Top laundries'), 'data' => $byLaundry] : null,
            ]) as $block)
                @php
                    $rows = $block['data']['rows'];
                    $top = max(array_column($rows, 'total') ?: [0]);
                @endphp
                <div class="{{ $byLaundry !== null ? 'col-lg-6' : 'col-12' }} mb-3">
                    <div class="card h-100">
                        <div class="card-header">
                            <h6 class="mb-0">{{ $block['title'] }}</h6>
                            <small class="text-muted">{{ __('Money taken this month') }}</small>
                        </div>
                        <div class="card-body">
                            @if ($rows === [])
                                <p class="viz-empty">{{ __('Nothing paid yet this month.') }}</p>
                            @else
                                {{-- One series, so one colour for every bar. --}}
                                <div class="viz-bars is-named" role="list">
                                    @foreach ($rows as $row)
                                        <div class="viz-bar-row" role="listitem">
                                            <span class="viz-bar-label" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                                            <span class="viz-bar-track">
                                                <span class="viz-bar-fill d-block"
                                                    style="width: {{ $top > 0 ? round($row['total'] / $top * 100, 1) : 0 }}%"></span>
                                            </span>
                                            <span class="viz-bar-value">{{ moneyFormat($row['total']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($block['data']['other'] > 0)
                                    <p class="small text-muted mt-2 mb-0">
                                        {{ __('And the rest') }}: {{ moneyFormat($block['data']['other']) }}
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- One variable: `@json([...])` with an array literal loses its escaping flags. --}}
    @php($moneyData = ['labels' => $dayLabels, 'values' => $dayValues, 'money' => $dayMoney])
    <script type="application/json" id="viz-data-money">@json($moneyData)</script>
    <script type="application/json" id="viz-data-methods">@json($methodSlices)</script>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var charts = window.laundoCharts;
            if (!charts) return;

            var money = charts.data('viz-data-money');
            charts.columns(document.getElementById('viz-money'), money.labels, money.values, money.money,
                @json(__('Money taken')), '--viz-s1');

            charts.donut(document.getElementById('viz-methods'), charts.data('viz-data-methods'), @json(__('Total')),
                charts.money(@json(app()->getLocale()), @json(appCurrency())));
        });
    </script>
@endpush
