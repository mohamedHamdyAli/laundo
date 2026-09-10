@php
    $profile = isset($row) ? $row->profile : null;
    $driverZones = isset($row) ? $row->zones->pluck('id')->all() : [];
    $readonly = Route::is('*.show');
@endphp

<div class="row g-2">
    <div class="col-12">
        <h6 class="border-bottom pb-2">{{ __('Personal Information') }}</h6>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $row->name ?? '') }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Phone') }} <span class="text-danger">*</span></label>
            <input type="text" name="phone" class="form-control"
                placeholder="{{ __('With the country code, e.g. +201012345678') }}"
                value="{{ old('phone', $row->phone ?? '') }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Email') }}</label>
            <input type="email" name="email" class="form-control" value="{{ old('email', $row->email ?? '') }}"
                {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Status') }}</label>
            <select name="status" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}</option>
                <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}</option>
            </select>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="is-available"
                    name="is_available" value="1"
                    {{ old('is_available', $profile?->is_available) ? 'checked' : '' }}
                    {{ $readonly ? 'disabled' : '' }}>
                <label class="form-check-label" for="is-available">{{ __('Receiving new tasks') }}</label>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Profile Image') }}</label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview"
                        src="{{ isset($row) && $row->image_profile ? getImageassetUrl($row->image_profile) : '' }}"
                        class="img-fluid rounded mb-2"
                        style="max-height: 130px; {{ isset($row) && $row->image_profile ? '' : 'display:none;' }}">
                    @if (!$readonly)
                        <input type="file" name="image_profile" class="form-control" onchange="previewImage(event)"
                            accept="image/*">
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if (!$readonly)
        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label">
                    {{ __('Password') }}{{ Route::is('*.create') ? '' : ' ' . __('(leave blank to keep current)') }}
                </label>
                <input type="password" name="password" class="form-control"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>

        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label">{{ __('Confirm Password') }}</label>
                <input type="password" name="password_confirmation" class="form-control"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    @endif

    <div class="col-12 mt-3">
        <h6 class="border-bottom pb-2">{{ __('Vehicle') }}</h6>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Vehicle Type') }}</label>
            @php
                // The stored value may predate the list — the column was free
                // text. `parse()` matches it case-insensitively so «Motorcycle»
                // selects Motorcycle instead of showing blank and being dropped
                // on the next save.
                $vehicleType = old('vehicle_type', \App\Modules\Driver\Enums\VehicleType::parse($profile?->vehicle_type)?->value);
            @endphp
            <select name="vehicle_type" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                <option value="">{{ __('Not set') }}</option>
                @foreach (\App\Modules\Driver\Enums\VehicleType::cases() as $case)
                    <option value="{{ $case->value }}" @selected($vehicleType === $case->value)>
                        {{ __($case->label()) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Plate Number') }}</label>
            <input type="text" name="plate_number" class="form-control"
                value="{{ old('plate_number', $profile?->plate_number) }}" {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-12 mt-3">
        <h6 class="border-bottom pb-2">
            {{ __('Documents') }}
            @if ($profile && $profile->hasExpiredDocuments())
                <span class="badge bg-danger">{{ __('Expired') }}</span>
            @endif
        </h6>
        <small class="text-muted">
            {{ __('Expiry dates are recorded and flagged here. They do not automatically stop task assignment.') }}
        </small>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('License Number') }}</label>
            <input type="text" name="license_number" class="form-control"
                value="{{ old('license_number', $profile?->license_number) }}" {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('License Expiry') }}</label>
            <input type="date" name="license_expiry" class="form-control"
                value="{{ old('license_expiry', $profile?->license_expiry?->toDateString()) }}"
                {{ $readonly ? 'readonly' : '' }}>
            @if ($profile && isset($profile->expiredDocuments()['license_expiry']))
                <div class="text-danger small">{{ __('This licence has expired.') }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-4">
        <div class="mb-3">
            <label class="form-label">{{ __('Vehicle Registration Expiry') }}</label>
            <input type="date" name="vehicle_registration_expiry" class="form-control"
                value="{{ old('vehicle_registration_expiry', $profile?->vehicle_registration_expiry?->toDateString()) }}"
                {{ $readonly ? 'readonly' : '' }}>
            @if ($profile && isset($profile->expiredDocuments()['vehicle_registration_expiry']))
                <div class="text-danger small">{{ __('This registration has expired.') }}</div>
            @endif
        </div>
    </div>

    @foreach ([
        'license_image' => 'License Image',
        'vehicle_registration_image' => 'Vehicle Registration',
        'national_id_image' => 'National ID',
    ] as $field => $label)
        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label">{{ __($label) }}</label>
                <div class="upload-field">
                    <div class="text-center">
                        @if ($profile?->{$field})
                            <a href="{{ getImageassetUrl($profile->{$field}) }}" target="_blank"
                                class="d-block small mb-2">{{ __('View current') }}</a>
                        @endif
                        @if (!$readonly)
                            <input type="file" name="{{ $field }}" class="form-control" accept="image/*">
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="col-12 mt-3">
        <h6 class="border-bottom pb-2">{{ __('Working Hours') }}</h6>
        <small class="text-muted">{{ __('One window, applied to every day.') }}</small>
    </div>

    {{-- What dispatch actually reads. A driver with neither set is uncapped and
         unrestricted by city — which is why both were invisibly null on every
         driver until now, and why the rules built on them never bit. --}}
    <div class="col-md-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Max Concurrent Orders') }}</label>
            <input type="number" min="1" max="50" name="max_concurrent_orders" class="form-control"
                placeholder="{{ __('e.g. 5') }}"
                value="{{ old('max_concurrent_orders', $row?->profile?->max_concurrent_orders ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
            <small class="text-muted">{{ __('Leave empty for no limit') }}</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label class="form-label">{{ __('City') }}</label>
            <select name="city_id" id="driver-city" class="form-select"
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Any city') }}</option>
                @foreach ($cities ?? [] as $city)
                    <option value="{{ $city->id }}"
                        {{ old('city_id', $row?->profile?->city_id ?? '') == $city->id ? 'selected' : '' }}>
                        {{ getLocalizedValueDashboard($city, 'name') }}
                    </option>
                @endforeach
            </select>
            <small class="text-muted">{{ __('Tasks outside it are not offered') }}</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Shift Start') }}</label>
            <input type="time" name="shift_start" class="form-control"
                value="{{ old('shift_start', $profile ? substr((string) $profile->shift_start, 0, 5) : '') }}"
                {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Shift End') }}</label>
            <input type="time" name="shift_end" class="form-control"
                value="{{ old('shift_end', $profile ? substr((string) $profile->shift_end, 0, 5) : '') }}"
                {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Notes') }}</label>
            <textarea name="notes" class="form-control" rows="2"
                {{ $readonly ? 'readonly' : '' }}>{{ old('notes', $profile?->notes) }}</textarea>
        </div>
    </div>

    <div class="col-12 mt-3">
        <h6 class="border-bottom pb-2">{{ __('Service Areas') }}</h6>
        <small class="text-muted">
            {{ __('The zones this driver covers. Dispatch matches an order pickup zone against these.') }}
        </small>
    </div>

    {{-- Grouped by city, and narrowed to the chosen one.

         Every city's zones used to be listed at once, so a driver whose city
         was Sharqia was offered Cairo's districts to tick — and dispatch would
         then match an order in Nasr City against a driver who cannot reach it.

         **A ticked zone is never hidden**, whatever city it belongs to. That
         is the whole rule here. Hiding one would leave coverage switched on
         and invisible: `hidden` does not stop a checkbox submitting, so an
         operator looking at Cairo would save and silently keep the Giza zones
         somebody set last month. A zone outside the chosen city that is
         already ticked stays on screen and is marked, so it can be seen and
         unticked deliberately.

         Rendered in full and narrowed in the browser rather than fetched: the
         whole list is a few dozen checkboxes, and a round trip on every change
         of a <select> would be a slower form and a second thing to keep
         working. --}}
    @forelse ($zonesByCity ?? [] as $cityId => $zones)
        <div class="col-12 mt-2 zone-head" data-zone-city="{{ $cityId }}">
            <strong class="small text-muted">
                {{ $zones->first()?->city ? getLocalizedValueDashboard($zones->first()->city, 'name') : __('No city') }}
            </strong>
        </div>
        @foreach ($zones as $zone)
            <div class="col-lg-3 col-md-4 col-sm-6 zone-item" data-zone-city="{{ $cityId }}">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="zones[]" value="{{ $zone->id }}"
                        id="zone-{{ $zone->id }}" {{ in_array($zone->id, $driverZones) ? 'checked' : '' }}
                        {{ $readonly ? 'disabled' : '' }}>
                    <label class="form-check-label" for="zone-{{ $zone->id }}">
                        {{ getLocalizedValueDashboard($zone, 'name') }}
                        <span class="zone-outside badge bg-warning text-dark ms-1" hidden>
                            {{ __('Outside the chosen city') }}
                        </span>
                    </label>
                </div>
            </div>
        @endforeach
    @empty
        <div class="col-12">
            <div class="alert alert-warning mb-0">
                {{ __('No active zones yet.') }}
                @if (canDo('zone.create'))
                    <a href="{{ route('admin.zone.create') }}">{{ __('Add a zone') }}</a>
                @endif
            </div>
        </div>
    @endforelse
</div>

@push('scripts')
    <script>
        // Narrow the zone list to the chosen city.
        //
        // The rule that matters: a ticked zone is never hidden. `hidden` does
        // not stop a checkbox submitting, so hiding a ticked one would leave
        // coverage switched on where nobody can see or remove it — an operator
        // would save the driver believing they cover Cairo and keep whatever
        // Giza zones were set months ago. Ticked zones outside the chosen city
        // stay visible and are flagged instead, so removing them is a decision
        // somebody makes rather than one the form makes for them.
        (function () {
            var city = document.getElementById('driver-city');
            var items = document.querySelectorAll('.zone-item');
            var heads = document.querySelectorAll('.zone-head');

            if (!city || !items.length) {
                return;
            }

            function apply() {
                var chosen = city.value;
                var visibleByCity = {};

                items.forEach(function (item) {
                    var box = item.querySelector('input[type="checkbox"]');
                    var mine = chosen === '' || item.dataset.zoneCity === chosen;
                    var ticked = box && box.checked;

                    // Visible when it belongs to the chosen city, or when it is
                    // ticked and therefore cannot be hidden.
                    var show = mine || ticked;

                    item.hidden = !show;

                    var flag = item.querySelector('.zone-outside');

                    if (flag) {
                        flag.hidden = mine || !ticked;
                    }

                    if (show) {
                        visibleByCity[item.dataset.zoneCity] = true;
                    }
                });

                // A city heading with nothing under it is a heading for
                // nothing.
                heads.forEach(function (head) {
                    head.hidden = !visibleByCity[head.dataset.zoneCity];
                });
            }

            apply();
            city.addEventListener('change', apply);

            // Unticking a zone from another city removes the last reason to
            // show it, so the list has to settle again.
            items.forEach(function (item) {
                var box = item.querySelector('input[type="checkbox"]');

                if (box) {
                    box.addEventListener('change', apply);
                }
            });
        })();
    </script>
@endpush
