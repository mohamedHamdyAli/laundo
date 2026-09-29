{{--
    «اختلاف في عدد القطع» — two counts of this order's pieces disagreed: a driver
    at a handover, or the laundry at its review (PieceCheck: the customer's
    order, then the handover before, then the laundry's review).

    At the top of the order, above everything, while it is open: it is the one
    thing on this page that means pieces may be missing, and the owner asked for
    it to be unmissable. The laundry sees it too — they are its pieces — but only
    the platform closes it, saying what it found and how many there really are.
    Once reviewed it moves to the short list underneath, which keeps the note.
--}}
@php
    $discrepancies = $row->pieceDiscrepancies;
    $open = $discrepancies->where('open', true);
    $reviewed = $discrepancies->where('open', false);
    $mayResolve = canDo('order.update') && ! \App\Support\LaundryContext::isTenant();
@endphp

@foreach ($open as $discrepancy)
    <div class="alert alert-danger border-danger" role="alert">
        <div class="d-flex gap-2 align-items-start">
            <i class="fa fa-exclamation-triangle fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong class="d-block">{{ __('The piece count does not match') }}</strong>
                <div>{{ \App\Modules\Notification\Services\PieceCountNotifier::describe($discrepancy) }}</div>
                <small class="d-block text-muted mt-1">
                    {{ humanDate($discrepancy->created_at) }}
                    @if ($discrepancy->task?->note) · {{ __('Driver note') }}: {{ $discrepancy->task->note }} @endif
                </small>

                @if ($mayResolve)
                    @php
                        // What was typed comes back only into the form it was typed
                        // in: with two open, one's explanation must not turn up in
                        // the other's box and close it with the wrong words.
                        $mine = (string) old('piece_check_id') === (string) $discrepancy->id;
                    @endphp
                    <form method="POST" action="{{ route('admin.order.piece_check.resolve', $discrepancy->id) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="piece_check_id" value="{{ $discrepancy->id }}">
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <label class="d-flex align-items-center gap-1 small mb-0">
                                {{ __('Pieces there really are') }}
                                <input type="number" name="piece_check_count" class="form-control form-control-sm"
                                    style="width: 5.5rem" min="0" max="999" required
                                    value="{{ $mine ? old('piece_check_count') : $discrepancy->counted }}">
                            </label>
                            <textarea name="piece_check_note" rows="1" class="form-control form-control-sm flex-grow-1"
                                style="min-width: 14rem" required minlength="3" maxlength="1000"
                                placeholder="{{ __('What did you find? e.g. called the customer — two pieces, one was a pair') }}">{{ $mine ? old('piece_check_note') : '' }}</textarea>
                            <button type="submit" class="btn btn-sm btn-danger">
                                <i class="fa fa-check me-1"></i>{{ __('Reviewed') }}
                            </button>
                        </div>
                    </form>
                @else
                    <small class="d-block mt-1">{{ __('The platform is looking into it.') }}</small>
                @endif
            </div>
        </div>
    </div>
@endforeach

@if ($reviewed->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('Piece count differences reviewed') }}</h6></div>
        <div class="card-body">
            @foreach ($reviewed as $discrepancy)
                <div class="small {{ $loop->last ? '' : 'mb-2 pb-2 border-bottom' }}">
                    <div>{{ \App\Modules\Notification\Services\PieceCountNotifier::describe($discrepancy) }}</div>
                    <div class="text-muted">
                        {{ __('Reviewed by :name', ['name' => $discrepancy->resolver?->name ?? __('the system')]) }}
                        @if ($discrepancy->confirmed_count !== null)
                            · {{ __('really :count', ['count' => $discrepancy->confirmed_count]) }}
                        @endif
                        · {{ humanDate($discrepancy->resolved_at) }}
                    </div>
                    @if ($discrepancy->note)
                        <div>{{ $discrepancy->note }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
