@forelse ($wallets as $wallet)
    {{-- A wallet whose balance disagrees with its own ledger is the one row on
         this screen somebody has to act on, so it takes the stripe. --}}
    <div class="stack-row {{ $wallet->isReconciled() ? '' : 'tone-bad' }}">
        <div>
            <span class="row-lead">
                @if (canDo('wallet.view'))
                    <a href="{{ route('admin.wallet.show', $wallet->id) }}">{{ $wallet->owner?->name ?? '—' }}</a>
                @else
                    {{ $wallet->owner?->name ?? '—' }}
                @endif
            </span>
            <span class="row-sub">{{ $wallet->owner?->phone ?? $wallet->owner?->email }}</span>
        </div>
        <div>
            @if ($wallet->isReconciled())
                <span class="status-pill tone-ok">{{ __('Balanced') }}</span>
            @else
                {{-- Surfaced rather than left to be discovered in a dispute. --}}
                <span class="status-pill tone-bad">{{ __('Does not match the ledger') }}</span>
                <span class="row-sub">{{ __('Ledger') }}: {{ moneyFormat($wallet->ledgerBalance()) }}</span>
            @endif
        </div>
        <div>
            @if ($wallet->is_frozen)
                <span class="status-pill tone-warn">{{ __('On hold') }}</span>
            @else
                <span class="status-pill tone-ok">{{ __('Active') }}</span>
            @endif
        </div>
        <div>
            @if ((float) $wallet->pending_balance > 0)
                <span class="row-main">{{ moneyFormat($wallet->pending_balance) }}</span>
                <span class="row-sub">{{ __('Not yet withdrawable') }}</span>
            @else
                <span class="row-sub">—</span>
            @endif
        </div>
        <div class="row-amount">
            {{ moneyFormat($wallet->balance) }}
            <span class="row-sub">{{ __('Balance') }}</span>
        </div>
        <div class="stack-actions">
            @if (canDo('wallet.view'))
                <a href="{{ route('admin.wallet.show', $wallet->id) }}" class="btn btn-sm action-btn action-view"
                    title="{{ __('View') }}" aria-label="{{ __('View') }}">
                    <i class="fa fa-eye"></i>
                </a>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
