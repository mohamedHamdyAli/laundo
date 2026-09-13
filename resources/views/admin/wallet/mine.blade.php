{{--
    «محفظتي» — your own wallet.

    Deliberately not `admin.wallet.show` with the id filled in: that screen
    carries the adjustment form and the freeze button, which are things done TO a
    wallet by somebody who is not its owner. This one only reads.
--}}
@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('My Wallet') }}
            @if ($row->is_frozen)
                <span class="badge bg-warning text-dark ms-2">{{ __('On hold') }}</span>
            @endif
        </h5>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Balance') }}</h6>
                        <h2 class="mb-3">{{ moneyFormat($row->balance) }}</h2>

                        @if ((float) $row->pending_balance > 0)
                            <h6 class="text-muted mb-1">{{ __('Pending') }}</h6>
                            <h4 class="mb-3">{{ moneyFormat($row->pending_balance) }}</h4>
                            <small class="text-muted d-block mb-3">
                                {{ __('Held until the order completes') }}
                            </small>
                        @endif

                        {{-- Shown to the owner too, not only to operations. A
                             balance that has drifted from its own ledger is
                             exactly the thing its owner would want to raise, and
                             hiding it from them means they raise it as «my money
                             is wrong» instead. --}}
                        <div class="alert {{ $reconciliation['reconciled'] ? 'alert-success' : 'alert-danger' }} py-2 mb-0">
                            <strong>
                                {{ $reconciliation['reconciled'] ? __('Balanced') : __('Does not match the ledger') }}
                            </strong>
                            <small class="d-block">
                                {{ __('Cached') }}: {{ moneyFormat($reconciliation['cached']) }} ·
                                {{ __('Ledger') }}: {{ moneyFormat($reconciliation['ledger']) }}
                            </small>
                        </div>
                    </div>
                </div>

                @if (canDo('order_settlement.view'))
                    <div class="card">
                        <div class="card-body">
                            <p class="text-muted small mb-2">
                                {{ __('Every credit here comes from an order. The settlement screen shows which one and how it was divided.') }}
                            </p>
                            <a href="{{ route('admin.settlement.index') }}" class="btn btn-outline-primary btn-sm w-100">
                                {{ __('Order Settlements') }}
                            </a>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Transactions') }}</h6></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Reason') }}</th>
                                        <th>{{ __('Balance After') }}</th>
                                        <th>{{ __('When') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($transactions as $transaction)
                                        <tr>
                                            <td class="{{ $transaction->isCredit() ? 'text-success' : 'text-danger' }}">
                                                <strong>
                                                    {{ $transaction->isCredit() ? '+' : '−' }}{{ moneyFormat($transaction->amount) }}
                                                </strong>
                                            </td>
                                            <td>
                                                {{ __($transaction->reason->label()) }}
                                                @if ($transaction->note)
                                                    <small class="text-muted d-block">{{ $transaction->note }}</small>
                                                @endif
                                            </td>
                                            <td>{{ moneyFormat($transaction->balance_after) }}</td>
                                            <td>{{ humanDate($transaction->created_at) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">
                                                {{ __('No transactions yet') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $transactions->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
