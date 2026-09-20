@php
    use App\Support\LaundryContext;
    $isTenant = LaundryContext::isTenant();
    $canEdit = canDo('laundry_slot_capacity.update');
@endphp

@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Intake Capacity') }}</h5>
        <span class="text-muted small">
            {{ __('How many orders each laundry can take in per pickup window.') }}
        </span>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        @include('layouts.validateMessage.errorMessage')

                        <p class="text-muted small">
                            {{ __('Leave a cell empty for no limit. Zero means the laundry takes nothing in that window — use it for a day it is closed.') }}
                            {{ __('The small number under each box is what is already booked for today.') }}
                            {{ __('Orders are never refused at checkout because of these numbers; they decide which laundry gets the order.') }}
                        </p>

                        @if ($laundries->isEmpty())
                            <div class="alert alert-warning mb-0">{{ __('No active laundry found.') }}</div>
                        @elseif ($slots->isEmpty())
                            <div class="alert alert-warning mb-0">
                                {{ __('No active pickup windows yet.') }}
                                @if (canDo('time_slot.create'))
                                    <a href="{{ route('admin.time_slot.create') }}">{{ __('Add a window') }}</a>
                                @endif
                            </div>
                        @else
                            <form action="{{ route('admin.laundry_slot_capacity.update') }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="list-toolbar">
                                    <input type="text" id="capacityFilterInput" class="form-control list-toolbar-search"
                                        placeholder="{{ __('Filter laundries...') }}" autocomplete="off">
                                    <span class="text-muted small align-self-center" id="capacityFilterInput-count"></span>
                                </div>
                                <div id="capacityFilterInput-empty" class="stack-empty" style="display: none">
                                    {{ __('No data found') }}
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle">
                                        <thead>
                                            <tr>
                                                <th style="min-width: 220px">{{ __('Laundry') }}</th>
                                                @foreach ($slots as $slot)
                                                    <th class="text-center" style="min-width: 120px">
                                                        {{ $slot->label() }}
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($laundries as $laundry)
                                                <tr data-filter-item>
                                                    <td>
                                                        <span class="fw-semibold">
                                                            {{ getLocalizedValueDashboard($laundry, 'name') }}
                                                        </span>
                                                        @if ($laundry->city)
                                                            <small class="d-block text-muted">
                                                                {{ getLocalizedValueDashboard($laundry->city, 'name') }}
                                                            </small>
                                                        @endif
                                                    </td>
                                                    @foreach ($slots as $slot)
                                                        @php
                                                            $value = $matrix[$laundry->id][$slot->id] ?? null;
                                                            $booked = $bookedToday[$laundry->id][$slot->id] ?? 0;
                                                        @endphp
                                                        <td class="text-center">
                                                            <input type="number" min="0" max="1000"
                                                                class="form-control form-control-sm text-center"
                                                                name="capacities[{{ $laundry->id }}][{{ $slot->id }}]"
                                                                value="{{ $value }}"
                                                                placeholder="{{ __('No limit') }}"
                                                                {{ $canEdit ? '' : 'disabled' }}>
                                                            <small class="text-muted">
                                                                {{ __('booked today: :n', ['n' => $booked]) }}
                                                            </small>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @if ($canEdit)
                                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                                @endif
                            </form>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        {{-- Filters what is already rendered. This screen posts the whole grid
             as one form, so a server-side re-render would blank every cell it
             did not draw and the save would wipe them. --}}
        setupClientFilter({
            inputSelector: '#capacityFilterInput',
            itemSelector: '[data-filter-item]',
            emptySelector: '#capacityFilterInput-empty',
            countSelector: '#capacityFilterInput-count',
        });
    </script>
@endpush
