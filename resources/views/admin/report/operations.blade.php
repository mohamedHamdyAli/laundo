@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Operations Health') }}</h5>
        <span class="text-muted small">{{ __('Right now — not a date range') }}</span>
    </div>

    <section class="section">
        @php
            $tiles = [
                ['label' => __('Waiting on a customer'), 'rows' => $snapshot['orders_awaiting_customer'], 'tone' => 'warning'],
                ['label' => __('Unassigned orders'), 'rows' => $snapshot['orders_unassigned'], 'tone' => 'warning'],
                ['label' => __('Tasks in the queue'), 'rows' => $snapshot['tasks_queued'], 'tone' => 'info'],
                ['label' => __('Tasks needing a person'), 'rows' => $snapshot['tasks_exhausted'], 'tone' => 'danger'],
                ['label' => __('Open price questions'), 'rows' => $snapshot['price_questions_open'], 'tone' => 'info'],
                ['label' => __('Wallets out of balance'), 'rows' => $snapshot['wallets_out_of_balance'], 'tone' => 'danger'],
            ];
        @endphp

        <div class="row mb-3">
            @foreach ($tiles as $tile)
                <div class="col-md-2">
                    <div class="card"><div class="card-body text-center">
                        <h6 class="text-muted mb-1 small">{{ $tile['label'] }}</h6>
                        <h3 class="mb-0 {{ count($tile['rows']) > 0 ? 'text-'.$tile['tone'] : 'text-success' }}">
                            {{ count($tile['rows']) }}
                        </h3>
                    </div></div>
                </div>
            @endforeach
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Refunds under review') }}</h6>
                    <h3 class="mb-0">{{ $snapshot['refunds_pending'] }}</h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Approved but unpaid') }}</h6>
                    <h3 class="mb-0 {{ $snapshot['refunds_unsettled'] > 0 ? 'text-danger' : '' }}">
                        {{ $snapshot['refunds_unsettled'] }}
                    </h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Failed notifications') }}</h6>
                    <h3 class="mb-0 {{ $snapshot['notifications_failed'] > 0 ? 'text-danger' : '' }}">
                        {{ $snapshot['notifications_failed'] }}
                    </h3>
                </div></div>
            </div>
        </div>

        {{-- The P7 decision was that an unanswered order waits indefinitely. That
             is only defensible while somebody can see the ones waiting. --}}
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('Orders waiting for a customer to confirm the price') }}</h6>
            </div>
            <div class="card-body">
                @forelse ($snapshot['orders_awaiting_customer'] as $row)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <a href="{{ route('admin.order.show', $row['id']) }}">#{{ $row['code'] }}</a>
                            <small class="text-muted d-block">
                                {{ $row['customer'] }} · {{ $row['phone'] }}
                            </small>
                        </div>
                        <div class="text-end">
                            <strong>{{ moneyFormat($row['total']) }}</strong>
                            <small class="d-block {{ ($row['waiting_hours'] ?? 0) > 24 ? 'text-danger' : 'text-muted' }}">
                                {{ $row['waiting_hours'] }} {{ __('h waiting') }}
                            </small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ __('Nothing is waiting on a customer.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Tasks needing a person') }}</h6></div>
                    <div class="card-body">
                        @forelse ($snapshot['tasks_exhausted'] as $row)
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <div>
                                    <a href="{{ route('admin.order.show', $row['order_id']) }}">
                                        #{{ $row['order_code'] }}
                                    </a>
                                    <small class="text-muted d-block">{{ $row['leg'] }}</small>
                                </div>
                                <small class="text-danger">
                                    {{ $row['attempts'] }} {{ __('attempts') }}
                                    @if ($row['reason']) — {{ $row['reason'] }} @endif
                                </small>
                            </div>
                        @empty
                            <p class="text-muted mb-0">{{ __('No task has run out of attempts.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Wallets out of balance') }}</h6></div>
                    <div class="card-body">
                        @forelse ($snapshot['wallets_out_of_balance'] as $row)
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <div>
                                    <a href="{{ route('admin.wallet.show', $row['id']) }}">{{ $row['owner'] }}</a>
                                    <small class="text-muted d-block">{{ $row['phone'] }}</small>
                                </div>
                                <small class="text-danger">
                                    {{ moneyFormat($row['cached']) }} vs {{ moneyFormat($row['ledger']) }}
                                </small>
                            </div>
                        @empty
                            {{-- The drift that is invisible until a customer
                                 disputes a figure. --}}
                            <p class="text-muted mb-0">{{ __('Every wallet matches its ledger.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
