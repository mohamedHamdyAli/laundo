@forelse ($refunds as $refund)
    @php
        // Approved but never paid out is the case that quietly disappears, so it
        // takes the stripe alongside an outright rejection.
        $needsAction = $refund->isAwaitingSettlement();
        $rejected = $refund->status === \App\Modules\Payment\Models\Refund::REJECTED;
    @endphp
    <div class="stack-row {{ $needsAction || $rejected ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                @if ($refund->order)
                    <a href="{{ route('admin.order.show', $refund->order->id) }}">#{{ $refund->order->code }}</a>
                @else
                    —
                @endif
            </span>
            <span class="row-sub">{{ humanDate($refund->created_at) }}</span>
        </div>
        <div>
            <span class="row-main">{{ $refund->customer?->name ?? '—' }}</span>
            <span class="row-sub">{{ $refund->customer?->phone }}</span>
        </div>
        <div>
            <span class="row-main">{{ $refund->reason }}</span>
            <span class="row-sub">{{ $refund->note ?: '—' }}</span>
        </div>
        <div>
            @if ($refund->status === \App\Modules\Payment\Models\Refund::SETTLED)
                <span class="status-pill tone-ok">{{ __($refund->statusLabel()) }}</span>
            @elseif ($rejected)
                <span class="status-pill tone-bad">{{ __($refund->statusLabel()) }}</span>
            @elseif ($needsAction)
                <span class="status-pill tone-warn">{{ __($refund->statusLabel()) }}</span>
            @else
                <span class="status-pill tone-live">{{ __($refund->statusLabel()) }}</span>
            @endif
            <span class="row-sub">
                @if ($needsAction)
                    {{ __('Payout pending') }}
                @elseif ($refund->reviewer)
                    {{ $refund->reviewer->name }}
                @else
                    —
                @endif
            </span>
        </div>
        <div class="row-amount">
            {{ moneyFormat($refund->amount) }}
        </div>
        <div class="stack-actions">
            @if (canDo('refund.update') && $refund->isPending())
                <button class="btn btn-sm act-text" data-bs-toggle="modal"
                    data-bs-target="#approve-{{ $refund->id }}">
                    {{ __('Approve') }}
                </button>

                <form method="POST" action="{{ route('admin.refund.reject', $refund->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm act-text act-text-danger">{{ __('Reject') }}</button>
                </form>

                <div class="modal fade" id="approve-{{ $refund->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('admin.refund.approve', $refund->id) }}"
                            class="modal-content">
                            @csrf
                            <div class="modal-header">
                                <h6 class="modal-title">
                                    {{ __('Refund') }} {{ moneyFormat($refund->amount) }}
                                </h6>
                            </div>
                            <div class="modal-body text-start">
                                <label class="form-label">{{ __('Where should it go?') }}</label>
                                <select name="destination" class="form-select mb-3" required>
                                    <option value="wallet">{{ __('Customer wallet (instant)') }}</option>
                                    <option value="source">{{ __('Back to the card') }}</option>
                                </select>
                                {{-- A wallet credit lands instantly; a gateway refund
                                     does not, and the customer is told different
                                     things. --}}
                                <small class="text-muted d-block mb-3">
                                    {{ __('A card refund is only possible if the order was paid by card.') }}
                                </small>
                                <label class="form-label">{{ __('Note') }}</label>
                                <textarea name="note" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    {{ __('Cancel') }}
                                </button>
                                <button type="submit" class="btn btn-success">{{ __('Approve') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @elseif (canDo('refund.update') && $needsAction)
                <form method="POST" action="{{ route('admin.refund.settle', $refund->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm act-text">{{ __('Retry payout') }}</button>
                </form>
            @else
                <span class="row-sub">—</span>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
