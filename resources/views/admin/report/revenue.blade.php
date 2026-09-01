@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Revenue Report') }}</h5>
        <span class="text-muted small">{{ $range->label() }}</span>
    </div>

    <section class="section">
        <div class="card mb-3">
            <div class="card-body">
                @include('admin.report.partials._range', ['exportable' => 'revenue'])
            </div>
        </div>

        @php
            $delta = fn ($now, $then) => $then > 0 ? round(($now - $then) / $then * 100, 1) : null;
            $netDelta = $delta($summary['net'], $previous['net']);
        @endphp

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Net revenue') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['net']) }}</h3>
                    @if ($netDelta !== null)
                        <small class="{{ $netDelta >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $netDelta >= 0 ? '▲' : '▼' }} {{ abs($netDelta) }}%
                            <span class="text-muted">{{ __('vs previous period') }}</span>
                        </small>
                    @endif
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Paid orders') }}</h6>
                    <h3 class="mb-0">{{ $summary['orders'] }}</h3>
                    <small class="text-muted">
                        {{ __('Average') }} {{ moneyFormat($summary['average_order']) }}
                    </small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Refunds paid') }}</h6>
                    <h3 class="mb-0 {{ $summary['refunds'] > 0 ? 'text-danger' : '' }}">
                        {{ moneyFormat($summary['refunds']) }}
                    </h3>
                    <small class="text-muted">{{ __('Dated by when they were paid out') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Receivables') }}</h6>
                    {{-- `text-warning` is #ffc107, which is 1.63:1 on white — an amber heading
                        nobody can read. `text-receivable` is the same signal at 5.02:1. --}}
                    <h3 class="mb-0 {{ $summary['receivables'] > 0 ? 'text-receivable' : '' }}">
                        {{ moneyFormat($summary['receivables']) }}
                    </h3>
                    {{-- Never inside revenue: this is owed, not earned. --}}
                    <small class="text-muted">{{ __('Delivered and not paid for') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">{{ __('Revenue per day') }}</h6>
                <small class="text-muted">
                    {{ __('Gross') }} {{ moneyFormat($summary['gross']) }} ·
                    {{ __('Delivery fees') }} {{ moneyFormat($summary['delivery_fees']) }} ·
                    {{ __('Discounts') }} {{ moneyFormat($summary['discounts']) }}
                </small>
            </div>
            <div class="card-body">
                @include('admin.report.partials._bars', ['series' => $daily, 'valueKey' => 'total'])
            </div>
        </div>

        <div class="row">
            @foreach ([
                ['title' => __('By laundry'), 'rows' => $byLaundry, 'key' => 'laundry'],
                ['title' => __('By service'), 'rows' => $byService, 'key' => 'service'],
                ['title' => __('By payment method'), 'rows' => $byMethod, 'key' => 'method'],
            ] as $block)
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">{{ $block['title'] }}</h6></div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    @forelse ($block['rows'] as $row)
                                        <tr>
                                            <td>{{ $row[$block['key']] }}</td>
                                            <td class="text-muted">{{ $row['orders'] }}</td>
                                            <td class="text-end"><strong>{{ moneyFormat($row['total']) }}</strong></td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-muted">{{ __('No data found') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endsection
