@forelse ($wallets as $wallet)
    <tr class="{{ $wallet->isReconciled() ? '' : 'table-danger' }}">
        <td>
            {{ $wallet->owner?->name ?? '—' }}
            <small class="text-muted d-block">
                {{ $wallet->owner?->phone ?? $wallet->owner?->email }}
            </small>
        </td>
        <td><strong>{{ moneyFormat($wallet->balance) }}</strong></td>
        <td>
            @if ((float) $wallet->pending_balance > 0)
                {{ moneyFormat($wallet->pending_balance) }}
                <small class="text-muted d-block">{{ __('Not yet withdrawable') }}</small>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
        <td>
            @if ($wallet->isReconciled())
                <span class="badge bg-success">{{ __('Balanced') }}</span>
            @else
                {{-- Surfaced rather than left to be discovered in a dispute. --}}
                <span class="badge bg-danger">{{ __('Does not match the ledger') }}</span>
                <small class="d-block text-danger">
                    {{ __('Ledger') }}: {{ moneyFormat($wallet->ledgerBalance()) }}
                </small>
            @endif
        </td>
        <td>
            @if ($wallet->is_frozen)
                <span class="badge bg-warning text-dark">{{ __('On hold') }}</span>
            @else
                <span class="badge bg-light text-dark">{{ __('Active') }}</span>
            @endif
        </td>
        <td class="text-center">
            @if (canDo('wallet.view'))
                <a href="{{ route('admin.wallet.show', $wallet->id) }}" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-eye"></i>
                </a>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
