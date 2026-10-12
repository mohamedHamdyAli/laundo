@forelse ($accounts as $account)
    {{-- An account whose balance disagrees with its own ledger is the one row
         somebody has to act on, so it takes the stripe. --}}
    @php($reconciled = $account->isReconciled())
    <div class="stack-row {{ $reconciled ? '' : 'tone-bad' }}">
        <div>
            <span class="row-lead">
                <a href="{{ route('admin.point_account.show', $account->user_id) }}">{{ $account->owner?->name ?? '—' }}</a>
            </span>
            <span class="row-sub">{{ $account->owner?->phone ?? $account->owner?->email }}</span>
        </div>
        <div>
            <span class="row-main">{{ $account->owner?->customer_reference ?? '—' }}</span>
        </div>
        <div>
            @if ($reconciled)
                <span class="status-pill tone-ok">{{ __('Balanced') }}</span>
            @else
                <span class="status-pill tone-bad">{{ __('Does not match the ledger') }}</span>
                <span class="row-sub">{{ __('Ledger') }}: {{ number_format($account->ledgerBalance()) }}</span>
            @endif
        </div>
        <div class="row-amount">
            {{ number_format($account->balance) }}
            <span class="row-sub">{{ moneyFormat($account->balance * $policy['point_value']) }}</span>
        </div>
        <div class="stack-actions">
            <a href="{{ route('admin.point_account.show', $account->user_id) }}" class="btn btn-sm action-btn action-view"
                title="{{ __('View') }}" aria-label="{{ __('View') }}">
                <i class="fa fa-eye"></i>
            </a>
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
