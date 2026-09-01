@php
    use App\Support\LaundryContext;
    // A tenant has exactly one laundry, so it gets no picker — its own is forced
    // server-side regardless of what the form posts.
    $isTenant = LaundryContext::isTenant();
@endphp

@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ $isTenant ? __('My Services') : __('Laundry Services') }}</h5>
        <span class="text-muted small">
            {{ __('Choose what this laundry offers. Prices are set globally and are not editable here.') }}
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
                        @elseif ($services->isEmpty())
                            <div class="alert alert-warning mb-0">{{ __('No active services yet.') }}</div>
                        @else
                            @unless ($isTenant)
                                {{-- Super admin: pick which laundry to edit. Reloads on change. --}}
                                <form method="GET" action="{{ route('admin.laundry_service.index') }}" class="mb-4">
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

                            <form action="{{ route('admin.laundry_service.update') }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="laundry_id" value="{{ $selectedLaundryId }}">

                                <div class="row g-3">
                                    @foreach ($services as $service)
                                        <div class="col-lg-6">
                                            <div class="card h-100 p-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        id="svc-{{ $service->id }}" name="services[]"
                                                        value="{{ $service->id }}"
                                                        {{ isset($enabled[$service->id]) ? 'checked' : '' }}
                                                        {{ canDo('laundry_service.update') ? '' : 'disabled' }}>
                                                    <label class="form-check-label fw-semibold"
                                                        for="svc-{{ $service->id }}">
                                                        {{ getLocalizedValueDashboard($service, 'name') }}
                                                    </label>
                                                </div>

                                                <div class="text-muted small mt-1">
                                                    @if ($service->pricing_mode === 'quote')
                                                        {{ __('Quoted after inspection') }}
                                                    @else
                                                        {{ __('Priced per item') }}
                                                    @endif
                                                    @php $d = $service->durationLabel(); @endphp
                                                    @if ($d)
                                                        · {{ $d }}
                                                        {{ __($service->duration_unit === 'day' ? 'days' : 'hours') }}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if (canDo('laundry_service.update'))
                                    <button type="submit" class="btn btn-primary mt-4">{{ __('Save') }}</button>
                                @endif
                            </form>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
