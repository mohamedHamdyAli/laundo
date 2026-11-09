@php
    $nameT = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Zone Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control"
                placeholder="{{ __('e.g. Nasr City') }}"
                value="{{ $nameT[getDefaultLanguage('code')] ?? '' }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'disabled' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('City') }} <span class="text-danger">*</span></label>
            <select name="city_id" id="zone-city" class="form-select" {{ Route::is('*.create') ? 'required' : '' }}
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Select City') }}</option>
                @foreach ($cities ?? [] as $city)
                    <option value="{{ $city->id }}" data-lat="{{ $city->lat }}" data-lng="{{ $city->lng }}"
                        {{ old('city_id', $row->city_id ?? '') == $city->id ? 'selected' : '' }}>
                        {{ getLocalizedValueDashboard($city, 'name') }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- No per-km rate any more: since «رسوم المشوار» (2026-11-08) a new order
         pays one figure per trip, set on the settings screen (`Trip_Fee`). The
         zone's `price_per_km` / `min_delivery_fee` columns stay, untouched by
         this form (a field not sent is left as it is), for the one thing that
         still reads them — re-measuring an order placed before the change when
         its laundry is reassigned. --}}

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Display Order') }}</label>
            <input type="number" min="0" name="sort_order" class="form-control"
                value="{{ old('sort_order', $row->sort_order ?? 0) }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Status') }}</label>
            <select name="status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}</option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}</option>
            </select>
        </div>
    </div>

    {{-- The zone on the map. A pin inside it is in this zone, whatever the app
         picked, and its laundries and drivers are the ones this zone has
         (ZoneLocator). Left undrawn, the zone keeps working the old way — the
         customer picks it from the list — so zones can be drawn one at a time. --}}
    <div class="col-12 form-divider">
        <div class="form-section-legend">{{ __('The zone on the map') }}</div>
    </div>

    <div class="col-12">
        <div class="mb-3">
            @php
                $drawn = old('boundary', isset($row) && $row->isDrawn() ? json_encode($row->polygon()->points()) : '');
            @endphp
            <input type="hidden" name="boundary" id="zone-boundary" value="{{ $drawn }}">
            <x-zone-drawer input="zone-boundary" city-select="zone-city"
                :others="$otherZones ?? []" :readonly="Route::is('*.show')" />
            @unless (isset($row) && $row->isDrawn())
                <small class="text-muted d-block mt-1">
                    {{ __('Not drawn yet: customers still pick this zone from the list until it is drawn.') }}
                </small>
            @endunless
        </div>
    </div>

    <div class="col-12 form-divider">
        <div class="form-section-legend">{{ __('Translation') }}</div>
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label class="form-label">{{ __('Zone Name') }} ({{ $language->name }})</label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    value="{{ $nameT[$language->code] ?? '' }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
