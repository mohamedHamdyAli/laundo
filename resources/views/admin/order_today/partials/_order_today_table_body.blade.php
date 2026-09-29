{{--
    One row per order, nearest first, each with an arrow that opens what the
    floor needs to know without leaving the page — the pieces, who and where,
    the handover and the notes. The code is the way into the order itself.

    Rendered by the index and by OrderTodayController@search, which replaces the
    container wholesale; the arrows are Bootstrap's own collapse, bound by data
    attributes, so a redrawn row needs no handler re-attached.
--}}
@forelse ($rows as $row)
    @php
        $order = $row['order'];
        $isPickup = $filters['scope'] === 'pickup_today';
        $date = $isPickup ? $order->pickup_date : $order->delivery_date;
        $slot = $isPickup ? $order->pickupSlot : $order->deliverySlot;
        $detailsId = 'order-today-details-' . $order->id;
    @endphp

    <div class="stack-row tone-{{ $order->status->tone() }}">
        <div>
            <span class="row-lead">
                <a href="{{ route('admin.order.show', $order->id) }}">#{{ $order->code }}</a>
            </span>
            <span class="row-sub">{{ $order->customer?->name ?? '-' }}</span>
        </div>

        <div>
            <span class="status-pill tone-{{ $order->status->tone() }}">{{ __($order->status->label()) }}</span>
            <span class="row-sub">
                {{ $order->service ? getLocalizedValueDashboard($order->service, 'name') : '-' }}
                @if ($laundries->isNotEmpty())
                    · {{ $order->laundry ? getLocalizedValueDashboard($order->laundry, 'name') : __('Unassigned') }}
                @endif
            </span>
        </div>

        <div>
            {{-- The pieces themselves, not only their number: «4 قميص · 2
                 بنطلون» is what the floor reads a row for. --}}
            <span class="row-main">
                {{ $row['total'] }}
                <small class="text-muted fw-normal">{{ $row['counted'] ? __('counted') : __('estimated') }}</small>
            </span>
            <span class="row-sub">
                {{ $row['pieces']->map(fn ($piece) => $piece['qty'] . ' ' . $piece['item'])->implode(' · ') ?: '—' }}
            </span>
        </div>

        <div>
            {{-- A plain date the customer picked, so formatted as it is: shifting
                 a date with no time through a timezone can move it a day. --}}
            <span class="row-main">
                @if ($date)
                    {{ $isPickup ? __('Pickup') : __('Delivery') }} {{ $date->format('Y-m-d') }}
                @else
                    {{ $isPickup ? __('No pickup date') : __('No delivery date yet') }}
                @endif
            </span>
            <span class="row-sub">{{ $slot?->label() ?? __('No window') }}</span>
        </div>

        <div class="stack-actions">
            <button type="button" class="btn btn-sm action-btn order-today-toggle"
                data-bs-toggle="collapse" data-bs-target="#{{ $detailsId }}"
                aria-expanded="false" aria-controls="{{ $detailsId }}"
                title="{{ __('Details') }}" aria-label="{{ __('Details') }} #{{ $order->code }}">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>

        <div class="collapse order-today-details" id="{{ $detailsId }}">
            <dl>
                <div>
                    <dt>{{ __('Pieces') }} ({{ $row['counted'] ? __('counted') : __('estimated') }})</dt>
                    <dd>
                        <div class="order-today-chips">
                            @forelse ($row['pieces'] as $piece)
                                <span class="order-today-chip">{{ $piece['qty'] }} {{ $piece['item'] }}</span>
                            @empty
                                <span class="text-muted">{{ __('Not counted yet') }}</span>
                            @endforelse
                        </div>
                    </dd>
                </div>

                <div>
                    <dt>{{ __('Customer') }}</dt>
                    <dd>
                        {{ $order->customer?->name ?? '-' }}
                        <small class="text-muted d-block">{{ $order->customer?->phone }}</small>
                    </dd>
                </div>

                <div>
                    <dt>{{ __('Pickup') }}</dt>
                    <dd>
                        {{ $order->pickup_date ? $order->pickup_date->format('Y-m-d') : '-' }}
                        <small class="text-muted d-block">{{ $order->pickupSlot?->label() }}</small>
                        <small class="text-muted d-block">{{ $order->pickupAddress?->street }}</small>
                    </dd>
                </div>

                <div>
                    <dt>{{ __('Delivery') }}</dt>
                    <dd>
                        {{ $order->delivery_date ? $order->delivery_date->format('Y-m-d') : '-' }}
                        <small class="text-muted d-block">{{ $order->deliverySlot?->label() }}</small>
                        @unless ($order->isRoundTrip())
                            <small class="text-warning d-block">
                                {{ __('Different address') }} — {{ $order->deliveryAddress?->street }}
                            </small>
                        @endunless
                    </dd>
                </div>

                <div>
                    <dt>{{ __('Handover') }}</dt>
                    <dd>
                        {{ __('Collection') }}:
                        {{ $order->pickup_method === 'leave' ? __('Leave at the door') : __('Hand to the customer') }}
                        <small class="text-muted d-block">
                            {{ __('Return') }}:
                            {{ $order->delivery_method === 'leave' ? __('Leave at the door') : __('Hand to the customer') }}
                        </small>
                    </dd>
                </div>

                <div>
                    <dt>{{ __('Total') }}</dt>
                    <dd>
                        {{ moneyFormat($order->payableTotal()) }}
                        <small class="text-muted d-block">
                            {{ $order->payment_method ? __(ucfirst($order->payment_method)) : '-' }}
                        </small>
                    </dd>
                </div>

                @if ($order->special_instructions)
                    <div>
                        <dt>{{ __('Special instructions') }}</dt>
                        <dd>{{ $order->special_instructions }}</dd>
                    </div>
                @endif

                @if ($order->driver_note)
                    <div>
                        <dt>{{ __('Note for the driver') }}</dt>
                        <dd>{{ $order->driver_note }}</dd>
                    </div>
                @endif

                @if ($order->pickupAddress?->driver_note)
                    <div>
                        <dt>{{ __('Note on the address') }}</dt>
                        <dd>{{ $order->pickupAddress->driver_note }}</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-2">
                <a href="{{ route('admin.order.show', $order->id) }}" class="btn btn-sm btn-outline-primary">
                    {{ __('Open the order') }}
                </a>
            </div>
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No orders here.') }}</div>
@endforelse
