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
            <select name="city_id" class="form-select" {{ Route::is('*.create') ? 'required' : '' }}
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="">{{ __('Select City') }}</option>
                @foreach ($cities ?? [] as $city)
                    <option value="{{ $city->id }}"
                        {{ old('city_id', $row->city_id ?? '') == $city->id ? 'selected' : '' }}>
                        {{ getLocalizedValueDashboard($city, 'name') }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- The delivery rate for this zone. The fee an order is charged is the
         straight-line distance from the laundry to the customer multiplied by
         this rate, floored at the minimum, and x1.5 when pickup and delivery are
         different addresses. Left empty, the fee cannot be worked out and the
         customer is told so rather than being shown a free delivery. --}}
    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Delivery Rate / km') }}</label>
            <input type="number" step="0.01" min="0" name="price_per_km" class="form-control"
                placeholder="{{ __('e.g. 5.00') }}"
                value="{{ old('price_per_km', $row->price_per_km ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
            <small class="text-muted">{{ __('Leave empty if not priced yet') }}</small>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label class="form-label">{{ __('Minimum Delivery Fee') }}</label>
            <input type="number" step="0.01" min="0" name="min_delivery_fee" class="form-control"
                placeholder="{{ __('e.g. 20.00') }}"
                value="{{ old('min_delivery_fee', $row->min_delivery_fee ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
            <small class="text-muted">{{ __('Floor for very short trips') }}</small>
        </div>
    </div>

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

    <div class="col-12">
        {{ __('Translation') }}
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
