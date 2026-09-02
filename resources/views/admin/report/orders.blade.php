@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Orders Report') }}</h5>
        <span class="text-muted small">{{ $range->label() }}</span>
    </div>

    <section class="section">
        <div class="card mb-3">
            <div class="card-body">
                @include('admin.report.partials._range', ['exportable' => 'orders'])
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Orders') }}</h6>
                    <h3 class="mb-0">{{ $summary['total'] }}</h3>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Completed') }}</h6>
                    <h3 class="mb-0 text-success">{{ $summary['completed'] }}</h3>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Cancellation rate') }}</h6>
                    <h3 class="mb-0 {{ $summary['cancellation_rate'] > 10 ? 'text-danger' : '' }}">
                        {{ $summary['cancellation_rate'] }}%
                    </h3>
                    {{-- «1 orders» is what a bare noun gives you at n=1. Both of these
                         sub-lines drop the noun — the heading above already names it —
                         so the phrase is grammatical at any count, in either language,
                         without a pluralisation table for two labels. --}}
                    <small class="text-muted">{{ $summary['cancelled'] }} {{ __('cancelled') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Never assigned') }}</h6>
                    <h3 class="mb-0 {{ $summary['unassigned_rate'] > 0 ? 'text-warning' : '' }}">
                        {{ $summary['unassigned_rate'] }}%
                    </h3>
                    <small class="text-muted">{{ __('No laundry covered them') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Orders per day') }}</h6></div>
            <div class="card-body">
                @include('admin.report.partials._bars', ['series' => $daily, 'valueKey' => 'orders'])
            </div>
        </div>

        {{-- The figure nobody asks for, and the one worth having: does counting
             the pieces systematically find more than the customer said? --}}
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('What the review does to the price') }}</h6>
            </div>
            <div class="card-body">
                @if ($priceMovement['reviewed'] === 0)
                    <p class="text-muted mb-0">{{ __('No orders were reviewed in this period.') }}</p>
                @else
                    <div class="row">
                        <div class="col-md-3">
                            <h6 class="text-muted mb-1">{{ __('Reviewed') }}</h6>
                            <h4>{{ $priceMovement['reviewed'] }}</h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted mb-1">{{ __('Price went up') }}</h6>
                            <h4>{{ $priceMovement['increase_rate'] }}%</h4>
                            <small class="text-muted">{{ $priceMovement['increased'] }} {{ __('of them') }}</small>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted mb-1">{{ __('Average change') }}</h6>
                            <h4 class="{{ $priceMovement['average_change'] > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $priceMovement['average_change'] > 0 ? '+' : '' }}{{ moneyFormat($priceMovement['average_change']) }}
                            </h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted mb-1">{{ __('Unchanged') }}</h6>
                            <h4>{{ $priceMovement['unchanged'] }}</h4>
                            <small class="text-muted">
                                {{ $priceMovement['decreased'] }} {{ __('went down') }}
                            </small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0 mt-3">
                        {{ __('A price that systematically rises means customers under-count — which is a wording problem in the app long before it is an argument with a customer.') }}
                    </p>
                @endif
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('By status') }}</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                @forelse ($byStatus as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="text-end"><strong>{{ $row['orders'] }}</strong></td>
                                    </tr>
                                @empty
                                    <tr><td class="text-muted">{{ __('No data found') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('By zone') }}</h6></div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                @forelse ($byZone as $row)
                                    <tr>
                                        <td>{{ $row['zone'] }}</td>
                                        <td class="text-end"><strong>{{ $row['orders'] }}</strong></td>
                                    </tr>
                                @empty
                                    <tr><td class="text-muted">{{ __('No data found') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
