@forelse ($legs as $leg)
    @php
        $eligible = $candidates[$leg->id] ?? [];
        $blocker = $blockers[$leg->id] ?? null;
        $openLegs = $openLegsByOrder[$leg->order_id] ?? 1;
        $failed = $leg->status->value === 'failed';

        // The area the leg happens in, which is what decides who is eligible.
        // The delivery leg is matched on the delivery address; every other leg
        // on the pickup address, the laundry having no zone of its own.
        $address = $leg->type->value === 'deliver_to_customer'
            ? ($leg->order->deliveryAddress ?? $leg->order->pickupAddress)
            : $leg->order->pickupAddress;
    @endphp

    {{-- A failed leg takes the stripe: it is not merely unclaimed, somebody
         tried and could not, and that is the row to read first. --}}
    <div class="stack-row {{ $failed ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                @if (canDo('order.view'))
                    <a href="{{ route('admin.order.show', $leg->order_id) }}">#{{ $leg->order->code }}</a>
                @else
                    #{{ $leg->order->code }}
                @endif
            </span>
            <span class="row-sub">{{ __($leg->order->status->label()) }}</span>
        </div>

        <div>
            <span class="row-main">{{ __($leg->type->label()) }}</span>
            @if ($failed)
                {{-- `failure_reason` is a cast enum, not a string — `__()` on the
                     enum itself reads as an array offset and takes the page down. --}}
                <span class="row-sub text-danger">
                    {{ $leg->failure_reason ? __($leg->failure_reason->label()) : __('Failed') }}
                    @if ($leg->attempts)
                        ({{ $leg->attempts }} {{ __('attempts') }})
                    @endif
                </span>
            @else
                <span class="row-sub">{{ __('Step :n of 4', ['n' => $leg->sequence]) }}</span>
            @endif
        </div>

        <div>
            <span class="row-main">{{ $leg->order->customer?->name ?? '—' }}</span>
            <span class="row-sub">{{ $leg->order->customer?->phone ?? '' }}</span>
        </div>

        <div>
            @if ($address?->zone)
                <span class="row-main">{{ getLocalizedValueDashboard($address->zone, 'name') }}</span>
            @else
                {{-- No zone means no rule can even be evaluated, so it is the
                     first thing to fix and it says so where it is visible. --}}
                <span class="status-pill tone-bad">{{ __('No area') }}</span>
            @endif
        </div>

        <div>
            <span class="row-main">{{ humanDate($leg->created_at) }}</span>
        </div>

        <div class="text-end">
            @if (canDo('order.update'))
                @if (! empty($eligible))
                    <form method="POST" action="{{ route('admin.order.tasks.assign', $leg->id) }}"
                        class="d-flex flex-column align-items-end gap-1">
                        @csrf
                        <div class="d-flex gap-1 justify-content-end">
                            <select name="driver_id" class="form-select form-select-sm"
                                style="max-width: 13rem;" required>
                                <option value="">{{ __('Choose a driver') }}</option>
                                @foreach ($eligible as $candidate)
                                    @php $load = $driverLoads[$candidate->id] ?? null; @endphp
                                    {{-- Sorted least-loaded-first by the
                                         dispatcher; the figure is what makes
                                         that visible. --}}
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->name }}@if ($load) —
                                            @if ($load['cap'])
                                                {{ __(':held of :cap', ['held' => $load['load'], 'cap' => $load['cap']]) }}
                                            @else
                                                {{ __(':held, no limit', ['held' => $load['load']]) }}
                                            @endif
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                {{ __('Assign') }}
                            </button>
                        </div>

                        @if ($openLegs > 1)
                            <label class="form-check form-check-sm small text-muted mb-0">
                                <input class="form-check-input" type="checkbox" name="rest_of_order" value="1">
                                {{ __('and the other :count legs of this order', ['count' => $openLegs - 1]) }}
                            </label>
                        @endif
                    </form>
                @else
                    <small class="d-block text-muted">{{ __('No eligible driver') }}</small>
                    @if ($blocker)
                        {{-- The whole reason this board is worth having: the row
                             says which of the six causes it is, so the operator
                             knows whether to add a zone, flip a switch, raise a
                             cap or fix the address. --}}
                        <small class="d-block text-attention">
                            {{ __($blocker['reason'], $blocker['params']) }}
                        </small>
                    @endif
                @endif
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">
        @if (filled($activeSearch ?? null) || filled($activeLeg ?? null))
            {{-- A search that matched nothing is not the same as an empty
                 board, and saying every journey has a driver when one does not
                 is a claim the operator would act on. --}}
            <strong class="d-block">{{ __('Nothing here matches that.') }}</strong>
            <small class="text-muted">{{ __('Clear the search to see everything waiting.') }}</small>
        @else
            <strong class="d-block">{{ __('Nothing is waiting for a driver.') }}</strong>
            <small class="text-muted">{{ __('Every journey has somebody on it.') }}</small>
        @endif
    </div>
@endforelse
