@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Time Slots') }}</h5>
        @if (canDo('time_slot.create'))
            <a href="{{ route('admin.time_slot.create') }}" class="badge alert-info primary-background-color">
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
                        </p>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Window') }}</th>
                                        <th>{{ __('Used For') }}</th>
                                        <th>{{ __('Capacity') }}</th>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($timeSlots as $slot)
                                        <tr>
                                            <td>{{ $slot->id }}</td>
                                            <td>{{ $slot->label() }}</td>
                                            <td>
                                                @if ($slot->applies_to === 'both')
                                                    <span class="badge bg-info">{{ __('Pickup & Delivery') }}</span>
                                                @elseif ($slot->applies_to === 'pickup')
                                                    <span class="badge bg-secondary">{{ __('Pickup') }}</span>
                                                @else
                                                    <span class="badge bg-secondary">{{ __('Delivery') }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $slot->capacity ?? __('Unlimited') }}</td>
                                            <td>{{ $slot->sort_order }}</td>
                                            <td>
                                                <x-status-toggle-button :id="$slot->id" :status="$slot->status"
                                                    endpoint="{{ route('admin.time_slot.toggleStatus', $slot->id) }}"
                                                    permission="time_slot.toggle" />
                                            </td>
                                            <td class="text-center">
                                                @include('admin.time_slot.shared.controlBut', ['row' => $slot])
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center">{{ __('No data found') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
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
