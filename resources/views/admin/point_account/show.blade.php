@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Points') }} — {{ $row->name }}</h5>
        <div class="d-flex gap-2">
            @if (canDo('user.view'))
                <a href="{{ route('admin.user.show', $row->id) }}" class="btn-quiet">
                    <i class="bi bi-person"></i>{{ __('Customer') }}
                </a>
            @endif
            <a href="{{ route('admin.point_account.index') }}" class="btn-quiet">
                <i class="fa fa-arrow-left"></i> {{ __('Back') }}
            </a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Points they can spend') }}</h6>
                        <h2 class="mb-1">{{ number_format($available) }}</h2>
                        <p class="text-muted small mb-3">
                            {{ __('Worth') }} {{ moneyFormat($available * $policy['point_value']) }}
                            · {{ $row->phone }}
                            @if ($row->customer_reference)
                                · {{ $row->customer_reference }}
                            @endif
                        </p>

                        @if ($nextExpiry)
                            <p class="mb-3">
                                <span class="status-pill tone-warn">
                                    {{ trans_choice(':count point expires on :date|:count points expire on :date', $nextExpiry['points'], [
                                        'count' => number_format($nextExpiry['points']),
                                        'date' => humanDate($nextExpiry['expires_at'], 'j M Y'),
                                    ]) }}
                                </span>
                            </p>
                        @endif

                        {{-- The proof, as on a wallet: the cached balance, the
                             ledger and the lots all say the same thing. --}}
                        <div class="alert {{ $reconciled ? 'alert-success' : 'alert-danger' }} py-2 mb-0">
                            <strong>{{ $reconciled ? __('Balanced') : __('Does not match the ledger') }}</strong>
                            <small class="d-block">
                                {{ __('Cached') }}: {{ number_format($account?->balance ?? 0) }} ·
                                {{ __('Ledger') }}: {{ number_format($ledgerBalance) }}
                            </small>
                        </div>
                    </div>
                </div>

                @if (canDo('point_account.update'))
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ __('Adjustment') }}</h6></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.point_account.adjust', $row->id) }}">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label" for="points-direction">{{ __('Direction') }}</label>
                                    <select name="direction" id="points-direction" class="form-select" required>
                                        <option value="credit">{{ __('Add points') }}</option>
                                        <option value="debit">{{ __('Take points off') }}</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="points-amount">{{ __('Points') }}</label>
                                    <input type="number" step="1" min="1" max="1000000" name="points" id="points-amount"
                                        class="form-control" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="points-note">
                                        {{ __('Reason') }} <span class="text-danger">*</span>
                                    </label>
                                    <textarea name="note" id="points-note" class="form-control" rows="2" maxlength="1000" required></textarea>
                                    <small class="text-muted">
                                        {{ __('Recorded against your name. Added points expire like earned ones; taken points come off the soonest to expire.') }}
                                    </small>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    {{ __('Record adjustment') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Movements') }}</h6></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Points') }}</th>
                                        <th>{{ __('Reason') }}</th>
                                        <th>{{ __('Balance After') }}</th>
                                        <th>{{ __('Expires') }}</th>
                                        <th>{{ __('When') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($transactions as $transaction)
                                        <tr>
                                            <td class="{{ $transaction->isCredit() ? 'text-success' : 'text-danger' }}">
                                                <strong>{{ $transaction->isCredit() ? '+' : '−' }}{{ number_format($transaction->points) }}</strong>
                                            </td>
                                            <td>
                                                {{ __($transaction->reason->label()) }}
                                                @if ($transaction->note)
                                                    <small class="text-muted d-block">{{ $transaction->note }}</small>
                                                @endif
                                                @if ($transaction->author)
                                                    <small class="text-muted d-block">{{ __('by') }} {{ $transaction->author->name }}</small>
                                                @endif
                                            </td>
                                            <td>{{ number_format($transaction->balance_after) }}</td>
                                            <td>
                                                @if ($transaction->isCredit())
                                                    {{ $transaction->expires_at ? humanDate($transaction->expires_at, 'j M Y') : __('Never') }}
                                                    @if ((int) $transaction->remaining < $transaction->points)
                                                        {{-- What is left of the lot, once some of it
                                                             has been spent or has lapsed. --}}
                                                        <small class="text-muted d-block">
                                                            {{ __(':left of :all left', ['left' => number_format((int) $transaction->remaining), 'all' => number_format($transaction->points)]) }}
                                                        </small>
                                                    @endif
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>{{ humanDate($transaction->created_at) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">{{ __('No points yet') }}</td>
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
