@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Wallet') }} — {{ $row->owner?->name }}
            @if ($row->is_frozen)
                <span class="badge bg-warning text-dark ms-2">{{ __('On hold') }}</span>
            @endif
        </h5>
        <a href="{{ route('admin.wallet.index') }}" class="badge alert-secondary">
            <i class="fa fa-arrow-left"></i> {{ __('Back') }}
        </a>
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
                        @endif

                        {{-- The proof. A cached balance that has drifted from its
                             own ledger is invisible until somebody disputes a
                             figure, so it is stated here every time. --}}
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

                @if (canDo('wallet.update'))
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ __('Adjustment') }}</h6></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.wallet.adjust', $row->id) }}">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label">{{ __('Direction') }}</label>
                                    <select name="direction" class="form-select" required>
                                        <option value="credit">{{ __('Add to the balance') }}</option>
                                        <option value="debit">{{ __('Take off the balance') }}</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">{{ __('Amount') }}</label>
                                    <input type="number" step="0.01" min="0.01" name="amount"
                                        class="form-control" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">
                                        {{ __('Reason') }} <span class="text-danger">*</span>
                                    </label>
                                    <textarea name="note" class="form-control" rows="2" required></textarea>
                                    {{-- Required: an adjustment nobody explained is
                                         one nobody can defend later. --}}
                                    <small class="text-muted">{{ __('Recorded against your name') }}</small>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    {{ __('Record adjustment') }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.wallet.freeze', $row->id) }}" class="mt-2">
                                @csrf
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                    {{ $row->is_frozen ? __('Release the wallet') : __('Place on hold') }}
                                </button>
                            </form>
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
                                                @if ($transaction->author)
                                                    <small class="text-muted d-block">
                                                        {{ __('by') }} {{ $transaction->author->name }}
                                                    </small>
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
