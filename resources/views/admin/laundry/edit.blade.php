@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Edit Laundry') }}</h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <form class="row g-3 needs-validation store" action="{{ route('admin.laundry.update', $row->id) }}"
                        method="Post" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.laundry.forms.formInput')
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Edit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Intake capacity, as a second form rather than fields inside the first.
         Nesting a form inside a form is invalid HTML and the inner one simply
         does not submit — and this one posts to a different route behind a
         different permission, because capacity decides how much work this
         laundry is handed and its owner must not hold that dial. --}}
    @if (canDo('laundry_slot_capacity.view'))
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header align-items-center d-flex">
                        <h5 class="card-title mb-0 flex-grow-1">{{ __('Intake Capacity') }}</h5>
                        @if (canDo('laundry_slot_capacity.view'))
                            <a href="{{ route('admin.laundry_slot_capacity.index') }}" class="btn btn-sm btn-outline-secondary">
                                {{ __('All laundries') }}
                            </a>
                        @endif
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('How many orders this laundry can take in per pickup window. Empty means no limit; zero means it takes nothing in that window.') }}
                        </p>

                        @if ($capacitySlots->isEmpty())
                            <div class="alert alert-warning mb-0">{{ __('No active pickup windows yet.') }}</div>
                        @else
                            <form action="{{ route('admin.laundry_slot_capacity.update') }}" method="POST" class="row g-3">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="return_to_laundry" value="{{ $row->id }}">

                                @foreach ($capacitySlots as $slot)
                                    <div class="col-md-3 col-sm-6">
                                        <label class="form-label" for="capacity-{{ $slot->id }}">{{ $slot->label() }}</label>
                                        <input type="number" min="0" max="1000" class="form-control"
                                            id="capacity-{{ $slot->id }}"
                                            name="capacities[{{ $row->id }}][{{ $slot->id }}]"
                                            value="{{ $capacities[$slot->id] ?? '' }}"
                                            placeholder="{{ __('No limit') }}"
                                            {{ canDo('laundry_slot_capacity.update') ? '' : 'disabled' }}>
                                    </div>
                                @endforeach

                                @if (canDo('laundry_slot_capacity.update'))
                                    <div class="col-12">
                                        <button class="btn btn-primary" type="submit">{{ __('Save capacity') }}</button>
                                    </div>
                                @endif
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

@endsection
