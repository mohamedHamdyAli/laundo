@php
    $nameT = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
    $descT = isset($row) ? (is_string($row->description) ? json_decode($row->description, true) : (array) $row->description) : [];
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="mb-3">
            <label for="service-name" class="form-label">{{ __('Service Name') }} <span class="text-danger">*</span></label>
            <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control" id="service-name"
                placeholder="{{ __('e.g. Wash & Iron') }}"
                value="{{ $nameT[getDefaultLanguage('code')] ?? '' }}"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'disabled' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="pricing-mode" class="form-label">{{ __('Pricing') }} <span class="text-danger">*</span></label>
            <select name="pricing_mode" id="pricing-mode" class="form-select"
                {{ Route::is('*.create') ? 'required' : '' }} {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="per_item" {{ old('pricing_mode', $row->pricing_mode ?? 'per_item') === 'per_item' ? 'selected' : '' }}>
                    {{ __('Per item — priced from the price matrix') }}
                </option>
                <option value="quote" {{ old('pricing_mode', $row->pricing_mode ?? '') === 'quote' ? 'selected' : '' }}>
                    {{ __('Quoted — priced after inspection') }}
                </option>
            </select>
            <div class="form-text">
                {{ __('A quoted service has no per-piece prices and never appears in the price grid.') }}
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label for="duration-min" class="form-label">{{ __('Duration From') }}</label>
            <input type="number" min="0" name="duration_min" class="form-control" id="duration-min"
                placeholder="24" value="{{ old('duration_min', $row->duration_min ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label for="duration-max" class="form-label">{{ __('Duration To') }}</label>
            <input type="number" min="0" name="duration_max" class="form-control" id="duration-max"
                placeholder="48" value="{{ old('duration_max', $row->duration_max ?? '') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>
            <div class="form-text">{{ __('Leave equal to "From" for a single value.') }}</div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label for="duration-unit" class="form-label">{{ __('Duration Unit') }}</label>
            <select name="duration_unit" id="duration-unit" class="form-select"
                {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="hour" {{ old('duration_unit', $row->duration_unit ?? 'hour') === 'hour' ? 'selected' : '' }}>
                    {{ __('Hours') }}</option>
                <option value="day" {{ old('duration_unit', $row->duration_unit ?? '') === 'day' ? 'selected' : '' }}>
                    {{ __('Days') }}</option>
            </select>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="mb-3">
            <label for="sort-order" class="form-label">{{ __('Display Order') }}</label>
            <input type="number" min="0" name="sort_order" class="form-control" id="sort-order"
                value="{{ old('sort_order', $row->sort_order ?? 0) }}" {{ Route::is('*.show') ? 'readonly' : '' }}>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="service-status" class="form-label">{{ __('Status') }}</label>
            <select name="status" id="service-status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}</option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}</option>
            </select>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Service Image') }}</label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" class="form-control" onchange="previewImage(event)"
                            accept="image/*">
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-12">
        <div class="mb-3">
            <label for="service-desc" class="form-label">{{ __('Description') }}</label>
            <textarea name="description[{{ getDefaultLanguage('code') }}]" id="service-desc" class="form-control"
                rows="2" placeholder="{{ __('Shown under the service name in the app.') }}"
                {{ Route::is('*.show') ? 'readonly' : '' }}>{{ $descT[getDefaultLanguage('code')] ?? '' }}</textarea>
        </div>
    </div>

    <div class="col-12 form-divider">
        <div class="form-section-legend">{{ __('Translation') }}</div>
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label class="form-label">{{ __('Service Name') }} ({{ $language->name }})</label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    value="{{ $nameT[$language->code] ?? '' }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="mb-3">
                <label class="form-label">{{ __('Description') }} ({{ $language->name }})</label>
                <textarea name="description[{{ $language->code }}]" class="form-control" rows="2"
                    {{ Route::is('*.show') ? 'readonly' : '' }}>{{ $descT[$language->code] ?? '' }}</textarea>
            </div>
        </div>
    @endforeach
</div>
