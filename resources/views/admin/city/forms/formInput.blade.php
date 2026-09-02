@php
    $nameTranslations = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
@endphp

<div class="row g-3">
    {{-- Name --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="city-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                <input type="text" name="name[{{ getDefaultLanguage('code') }}]" id="city-name" class="form-control"
                    placeholder="{{ __('Enter City Name') }}"
                    value="{{ $nameTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }} {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Country') }}</label>
            <div class="controls">
                <select name="country_id" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="">{{ __('Select Country') }}</option>
                    @foreach ($countries as $country)
                        @php
                            $countryName = is_string($country->name)
                                ? json_decode($country->name, true)[getDefaultLanguage('code')] ?? ''
                                : $country->name->{getDefaultLanguage('code')} ?? '';
                        @endphp
                        <option value="{{ $country->id }}"
                            {{ isset($row) && $row->country_id == $country->id ? 'selected' : '' }}>
                            {{ $countryName }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
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
                <label for="city-name-{{ $language->code }}" class="form-label">
                    {{ __('Name') }} ({{ $language->name }})
                </label>
                <input type="text" name="name[{{ $language->code }}]" id="city-name-{{ $language->code }}"
                    class="form-control" placeholder="{{ __('Enter City Name in') }} {{ $language->name }}"
                    value="{{ $nameTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>
