@forelse ($refunds as $refund)
    <tr>
        <td>
            @if ($refund->order)
                <a href="{{ route('admin.order.show', $refund->order->id) }}">#{{ $refund->order->code }}</a>
            @else
                —
            @endif
        </td>
        <td>
            {{ $refund->customer?->name ?? '—' }}
            <small class="text-muted d-block">{{ $refund->customer?->phone }}</small>
        </td>
        <td><strong>{{ moneyFormat($refund->amount) }}</strong></td>
        <td>
            {{ $refund->reason }}
            @if ($refund->note)
                <small class="text-muted d-block">{{ $refund->note }}</small>
            @endif
        </td>
        <td>
            <span class="badge
                @if ($refund->status === \App\Modules\Payment\Models\Refund::SETTLED) bg-success
                @elseif ($refund->status === \App\Modules\Payment\Models\Refund::REJECTED) bg-secondary
                @elseif ($refund->isAwaitingSettlement()) bg-warning text-dark
                @else bg-info @endif">
                {{ __($refund->statusLabel()) }}
            </span>
            @if ($refund->isAwaitingSettlement())
                {{-- Approved but never paid out. Without this it disappears. --}}
                <small class="d-block text-danger">{{ __('Payout pending') }}</small>
            @endif
            @if ($refund->reviewer)
                <small class="text-muted d-block">{{ $refund->reviewer->name }}</small>
            @endif
        </td>
        <td>{{ humanDate($refund->created_at) }}</td>
        <td class="text-center">
            @if (canDo('refund.update') && $refund->isPending())
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                    data-bs-target="#approve-{{ $refund->id }}">
                    {{ __('Approve') }}
                </button>

                <form method="POST" action="{{ route('admin.refund.reject', $refund->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Reject') }}</button>
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
            @elseif (canDo('refund.update') && $refund->isAwaitingSettlement())
                <form method="POST" action="{{ route('admin.refund.settle', $refund->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-warning">{{ __('Retry payout') }}</button>
                </form>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
