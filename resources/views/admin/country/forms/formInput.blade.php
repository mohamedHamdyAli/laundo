@php
    $nameTranslations = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row g-3">
    {{-- Name --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="country-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                <input type="text" name="name[{{ getDefaultLanguage('code') }}]" id="country-name" class="form-control"
                    placeholder="{{ __('Enter Country Name') }}"
                    value="{{ $nameTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }} {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    {{-- Code --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="country-code" class="form-label">{{ __('Code') }}</label>
            <div class="controls">
                <input type="text" name="code" id="country-code" class="form-control"
                    placeholder="{{ __('Enter Country Code') }}" value="{{ $row->code ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }} {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    {{-- Phone Code --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="country-phone" class="form-label">{{ __('Phone Code') }}</label>
            <div class="controls">
                <input type="text" name="phone_code" id="country-phone" class="form-control"
                    placeholder="{{ __('Enter Phone Code') }}" value="{{ $row->phone_code ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    </div>

    {{-- Timezone --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="country-timezone" class="form-label">{{ __('Timezone') }}</label>
            <div class="controls">
                <input type="text" name="timezone" id="country-timezone" class="form-control"
                    placeholder="{{ __('e.g. Asia/Kuwait (auto-filled for known countries if left blank)') }}"
                    value="{{ $row->timezone ?? '' }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="mb-3">
            <label for="status-select" class="form-label">{{ __('Status') }}</label>
            <select name="status" id="status-select" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                    {{ __('Active') }}
                </option>
                <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                    {{ __('Inactive') }}
                </option>
            </select>
        </div>
    </div>
</div>

{{-- Translation Optional --}}
<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Translation') }}</div>
</div>
<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-md-6">
            <div class="form-group">
                <label for="country-name-{{ $language->code }}" class="form-label">
                    {{ __('Name') }} ({{ $language->name }})
                </label>
                <input type="text" name="name[{{ $language->code }}]" id="country-name-{{ $language->code }}"
                    class="form-control" placeholder="{{ __('Enter Country Name in') }} {{ $language->name }}"
                    value="{{ $nameTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
