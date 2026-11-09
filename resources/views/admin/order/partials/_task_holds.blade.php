{{--
    «بانتظار موافقة الإدارة» — a driver's «تأكيد» held because the count at the
    laundry or the cash at the door did not match (the client's sheet, items 9
    and 10; `TaskHolds`, 2026-11-08).

    At the top of the order, beside the piece-count alerts: the leg is stopped
    until somebody answers. Approve completes it with what the driver sent
    (more cash than owed goes to the customer's wallet once the bill is
    agreed — at the pickup it is a payment towards it; less stays owed);
    refuse sends it back with the note as the reason. The platform decides —
    a laundry sees the question but has no buttons — and cash needs
    `payment.update` beside `order.update`.
--}}
@php
    $holds = $row->taskHolds->where('status', \App\Modules\Order\Models\TaskHold::PENDING);
    $inLaundry = \App\Support\LaundryContext::isTenant();
@endphp

@foreach ($holds as $hold)
    @php
        $mayDecide = ! $inLaundry && canDo('order.update') && canDo(\App\Modules\Order\Services\TaskHolds::permissionFor($hold));
        $mine = (string) old('hold_id') === (string) $hold->id;
    @endphp
    <div class="alert alert-warning border-warning" role="alert" data-task-hold="{{ $hold->id }}">
        <div class="d-flex gap-2 align-items-start">
            <i class="fa fa-hand-paper fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong class="d-block">
                    {{ $hold->isMoney() ? __('Waiting for your approval — the amount collected') : __('Waiting for your approval — the count at the laundry') }}
                </strong>
                <div>{{ $hold->describe() }}</div>
                <small class="d-block text-muted mt-1">
                    {{ __($hold->task?->type?->label() ?? '') }}
                    · {{ $hold->raisedBy?->name ?? __('The driver') }}
                    · {{ humanDate($hold->updated_at) }}
                    @if ($hold->payload['note'] ?? null) · {{ __('Driver note') }}: {{ $hold->payload['note'] }} @endif
                </small>

                @if ($mayDecide)
                    <div class="d-flex flex-wrap gap-2 align-items-start mt-2">
                        <form method="POST" action="{{ route('admin.order.hold.approve', $hold->id) }}" class="d-flex flex-wrap gap-2 align-items-start flex-grow-1">
                            @csrf
                            <input type="hidden" name="hold_id" value="{{ $hold->id }}">
                            <textarea name="hold_note" rows="1" class="form-control form-control-sm flex-grow-1"
                                style="min-width: 14rem" maxlength="1000"
                                placeholder="{{ __('A note (needed to refuse — the driver is told it)') }}">{{ $mine ? old('hold_note') : '' }}</textarea>
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="fa fa-check me-1"></i>{{ __('Approve') }}
                            </button>
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                formaction="{{ route('admin.order.hold.reject', $hold->id) }}">
                                <i class="fa fa-times me-1"></i>{{ __('Refuse') }}
                            </button>
                        </form>
                    </div>
                @else
                    <small class="d-block mt-1">{{ __('The platform is looking into it.') }}</small>
                @endif
            </div>
        </div>
    </div>
@endforeach
