@php
    $nameTranslations = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-name" class="form-label">{{ __('Laundry Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control" id="laundry-name"
                placeholder="{{ __('Enter Laundry Name') }}"
                value="{{ $nameTranslations[getDefaultLanguage('code')] ?? '' }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'disabled' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-phone" class="form-label">{{ __('Phone') }} <span class="text-danger">*</span></label>
            <input type="text" name="phone" class="form-control" id="laundry-phone"
                placeholder="{{ __('With the country code, e.g. +201012345678') }}"
                value="{{ old('phone', $row->phone ?? '') }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-email" class="form-label">{{ __('Email') }}</label>
            <input type="email" name="email" class="form-control" id="laundry-email"
                placeholder="{{ __('Enter Email') }}" value="{{ old('email', $row->email ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-city" class="form-label">{{ __('City') }}</label>
            <select name="city_id" id="laundry-city" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Select City') }}</option>
                @foreach ($cities ?? [] as $city)
                    {{-- The coordinates ride on the option so the map can
                         re-centre without a round trip. A city with none yet
                         emits nothing and the map stays where it is. --}}
                    <option value="{{ $city->id }}"
                        @if ($city->lat !== null && $city->lng !== null)
                            data-lat="{{ $city->lat }}" data-lng="{{ $city->lng }}"
                        @endif
                        {{ old('city_id', $row->city_id ?? '') == $city->id ? 'selected' : '' }}>
                        {{ getLocalizedValueDashboard($city, 'name') }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-address" class="form-label">{{ __('Address') }}</label>
            <textarea name="address" id="laundry-address" class="form-control" rows="2"
                placeholder="{{ __('Enter Address') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>{{ old('address', $row->address ?? '') }}</textarea>
        </div>
    </div>

    {{-- The point delivery fees are measured from. A laundry without one cannot
         have its orders priced for delivery — the customer is shown «to be
         confirmed» rather than a fee of zero. --}}
    <div class="col-lg-3">
        <div class="mb-3">
            <label for="laundry-lat" class="form-label">{{ __('Latitude') }}</label>
            <input type="number" step="0.0000001" name="lat" id="laundry-lat" class="form-control"
                placeholder="{{ __('e.g. 30.0444') }}"
                value="{{ old('lat', $row->lat ?? '') }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label for="laundry-lng" class="form-label">{{ __('Longitude') }}</label>
            <input type="number" step="0.0000001" name="lng" id="laundry-lng" class="form-control"
                placeholder="{{ __('e.g. 31.2357') }}"
                value="{{ old('lng', $row->lng ?? '') }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
            <small class="text-muted">{{ __('Used to calculate delivery fees') }}</small>
        </div>
    </div>

    {{-- Full width: the two number boxes above are the stored value, this is how
         somebody arrives at it. Bound to the city select, so choosing a
         governorate moves the view there instead of leaving the pin to be
         dragged across the country. --}}
    <div class="col-12">
        <div class="mb-3">
            {{-- `inputs="readonly"` locks the two boxes: the numbers stay
                 visible because they are what gets stored, but the map is the
                 only thing that writes them, so a mistyped digit cannot put a
                 laundry in the sea. Swap to `inputs="hidden"` to drop the
                 boxes from the form entirely. --}}
            <x-map-picker lat-input="laundry-lat" lng-input="laundry-lng" city-select="laundry-city"
                inputs="readonly" :readonly="Route::is('*.show')"
                :label="__('Location on the map')" />
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="laundry-status" class="form-label">{{ __('Status') }}</label>
            <select name="status" id="laundry-status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}
                </option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}
                </option>
            </select>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Logo') }}</label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->logo ? getImageassetUrl($row->logo) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->logo ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="logo" class="form-control" onchange="previewImage(event)"
                            accept="image/*">
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- A laundry with no way to sign in is useless, so the owner account is
         created with it in one transaction. Editing an owner afterwards happens
         through Laundry Staff. --}}
    @if (Route::is('*.create'))
        <div class="col-12 mt-2">
            <h6 class="mb-0">{{ __('Owner Account') }}</h6>
            <small class="text-muted">{{ __('This account signs in to the laundry dashboard.') }}</small>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="owner-name" class="form-label">{{ __('Owner Name') }} <span class="text-danger">*</span></label>
                <input type="text" name="owner_name" class="form-control" id="owner-name"
                    placeholder="{{ __('Enter Owner Name') }}" value="{{ old('owner_name') }}" required>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="owner-phone" class="form-label">{{ __('Owner Phone') }} <span class="text-danger">*</span></label>
                <input type="text" name="owner_phone" class="form-control" id="owner-phone"
                    placeholder="{{ __('With the country code, e.g. +201012345678') }}"
                    value="{{ old('owner_phone') }}" required>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="owner-email" class="form-label">{{ __('Owner Email') }} <span class="text-danger">*</span></label>
                <input type="email" name="owner_email" class="form-control" id="owner-email"
                    placeholder="{{ __('Enter Owner Email') }}" value="{{ old('owner_email') }}" required>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="mb-3">
                <label for="owner-password" class="form-label">{{ __('Password') }} <span class="text-danger">*</span></label>
                <input type="password" name="owner_password" class="form-control" id="owner-password"
                    placeholder="{{ __('Enter Password') }}" required>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="mb-3">
                <label for="owner-password-confirm" class="form-label">{{ __('Confirm Password') }} <span class="text-danger">*</span></label>
                <input type="password" name="owner_password_confirmation" class="form-control"
                    id="owner-password-confirm" placeholder="{{ __('Confirm Password') }}" required>
            </div>
        </div>
    @endif

    {{-- Editing, and the laundry has an owner account.

         Its name, email and phone are the person's, not the laundry record's,
         so they are shown and not edited. The password is here because when an
         owner is locked out this is the screen somebody goes looking on — it
         was previously possible only through «Laundry Staff», which lists
         owners by accident of its `role.type = laundry` scope. --}}
    @if (Route::is('*.edit') && ($row->owner ?? null))
        <div class="col-12 form-divider">
            <div class="form-section-legend">{{ __('Owner Account') }}</div>
        </div>

        <div class="col-12">
            <p class="text-muted mb-3">
                {{ __('Signs in as') }} <strong>{{ $row->owner->email }}</strong>
                &mdash; {{ $row->owner->name }}.
                {{ __('Leave the password blank to keep the current one.') }}
            </p>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="owner-password" class="form-label">{{ __('New Password') }}</label>
                <input type="password" name="owner_password" class="form-control" id="owner-password"
                    placeholder="{{ __('Leave blank to keep the current password') }}" autocomplete="new-password">
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="owner-password-confirm" class="form-label">{{ __('Confirm New Password') }}</label>
                <input type="password" name="owner_password_confirmation" class="form-control"
                    id="owner-password-confirm" placeholder="{{ __('Confirm Password') }}"
                    autocomplete="new-password">
            </div>
        </div>
    @endif

    <div class="col-12 form-divider">
        <div class="form-section-legend">{{ __('Translation') }}</div>
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label for="laundry-name-{{ $language->code }}" class="form-label">
                    {{ __('Laundry Name') }} ({{ $language->name }})
                </label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    id="laundry-name-{{ $language->code }}"
                    placeholder="{{ __('Enter Laundry Name in') }} {{ $language->name }}"
                    value="{{ $nameTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
