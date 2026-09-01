@php
    use App\Support\LaundryContext;
    $isTenant = LaundryContext::isTenant();
@endphp

@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ $isTenant ? __('My Areas') : __('Laundry Service Areas') }}</h5>
        <span class="text-muted small">
            {{ __('Pick the zones this laundry collects from and delivers to.') }}
        </span>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        @include('layouts.validateMessage.errorMessage')

                        @if (! $selectedLaundryId)
                            <div class="alert alert-warning mb-0">{{ __('No active laundry found.') }}</div>
                        @elseif ($zonesByCity->isEmpty())
                            <div class="alert alert-warning mb-0">
                                {{ __('No active zones yet.') }}
                                @if (canDo('zone.create'))
                                    <a href="{{ route('admin.zone.create') }}">{{ __('Add a zone') }}</a>
                                @endif
                            </div>
                        @else
                            @unless ($isTenant)
                                <form method="GET" action="{{ route('admin.laundry_zone.index') }}" class="mb-4">
                                    <label class="form-label">{{ __('Laundry') }}</label>
                                    <div class="d-flex gap-2" style="max-width: 420px;">
                                        <select name="laundry_id" class="form-select" onchange="this.form.submit()">
                                            @foreach ($laundries as $laundry)
                                                <option value="{{ $laundry->id }}"
                                                    {{ $selectedLaundryId == $laundry->id ? 'selected' : '' }}>
                                                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </form>
                            @endunless

                            <form action="{{ route('admin.laundry_zone.update') }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="laundry_id" value="{{ $selectedLaundryId }}">

                                {{-- Grouped by city: the zone list grows fast and a flat
                                     list of every governorate is unusable. --}}
                                @foreach ($zonesByCity as $cityName => $zones)
                                    <div class="mb-4">
                                        <h6 class="border-bottom pb-2">{{ $cityName }}</h6>
                                        <div class="row g-2">
                                            @foreach ($zones as $zone)
                                                <div class="col-lg-3 col-md-4 col-sm-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                            id="zone-{{ $zone->id }}" name="zones[]"
                                                            value="{{ $zone->id }}"
                                                            {{ in_array($zone->id, $enabled) ? 'checked' : '' }}
                                                            {{ canDo('laundry_zone.update') ? '' : 'disabled' }}>
                                                        <label class="form-check-label" for="zone-{{ $zone->id }}">
                                                            {{ getLocalizedValueDashboard($zone, 'name') }}
                                                        </label>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                @if (canDo('laundry_zone.update'))
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
