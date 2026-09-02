@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Time Slots') }}</h5>
        @if (canDo('time_slot.create'))
            <a href="{{ route('admin.time_slot.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Time Slot') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('These windows apply to every day. An empty capacity means unlimited.') }}
                            {{ __('A pickup and a delivery in the same window are two separate journeys, so both count against it.') }}
                        </p>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(8rem,auto) minmax(7rem,.9fr) minmax(7rem,.9fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Window') }}</span>
                            <span>{{ __('Used For') }}</span>
                            <span>{{ __('Booked today') }}</span>
                            <span>{{ __('Booked tomorrow') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" style="--stack-cols: {{ $stackCols }}">
                            @forelse ($timeSlots as $slot)
                                @php
                                    // Only a capped window can be full, and only a full one is
                                    // worth colouring — a red number on an unlimited window
                                    // would mean nothing.
                                    $capped = $slot->capacity !== null;
                                    $usage_ = [
                                        'today' => $usage[$slot->id]['today'] ?? 0,
                                        'tomorrow' => $usage[$slot->id]['tomorrow'] ?? 0,
                                    ];
                                    $fullToday = $capped && $usage_['today'] >= (int) $slot->capacity;
                                    $fullTomorrow = $capped && $usage_['tomorrow'] >= (int) $slot->capacity;
                                @endphp
                                <div class="stack-row {{ $fullToday || $fullTomorrow ? 'tone-bad' : '' }}">
                                    <div>
                                        <span class="row-lead">{{ $slot->label() }}</span>
                                        <span class="row-sub">#{{ $slot->id }} · {{ __('Order') }} {{ $slot->sort_order }}</span>
                                    </div>
                                    <div>
                                        @if ($slot->applies_to === 'both')
                                            <span class="status-pill tone-live">{{ __('Pickup & Delivery') }}</span>
                                        @elseif ($slot->applies_to === 'pickup')
                                            <span class="status-pill tone-warn">{{ __('Pickup') }}</span>
                                        @else
                                            <span class="status-pill tone-warn">{{ __('Delivery') }}</span>
                                        @endif
                                    </div>
                                    @foreach (['today' => $fullToday, 'tomorrow' => $fullTomorrow] as $day => $isFull)
                                        <div>
                                            @if (! $capped)
                                                <span class="row-main">{{ $usage_[$day] }}</span>
                                                <span class="row-sub">{{ __('Unlimited') }}</span>
                                            @else
                                                <span class="row-main">{{ $usage_[$day] }} / {{ $slot->capacity }}</span>
                                                @if ($isFull)
                                                    <span class="status-pill tone-bad">{{ __('Full') }}</span>
                                                @else
                                                    <span class="row-sub">{{ (int) $slot->capacity - $usage_[$day] }} {{ __('left') }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                    <div>
                                        <x-status-toggle-button :id="$slot->id" :status="$slot->status"
                                            endpoint="{{ route('admin.time_slot.toggleStatus', $slot->id) }}"
                                            permission="time_slot.toggle" />
                                    </div>
                                    <div class="stack-actions">
                                        @include('admin.time_slot.shared.controlBut', ['row' => $slot])
                                    </div>
                                </div>
                            @empty
                                <div class="stack-empty">{{ __('No data found') }}</div>
                            @endforelse
                        </div>

                        <div id="pagination-wrapper">
                            {{ $timeSlots->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
