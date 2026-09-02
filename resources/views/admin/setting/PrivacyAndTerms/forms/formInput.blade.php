{{-- Privacy_Policy / Terms  --}}
@php
    $PrivacyPolicyJson = getSettingValue('Privacy_Policy');
    $PrivacyPolicyTranslations = !empty($PrivacyPolicyJson) ? json_decode($PrivacyPolicyJson, true) : [];

    $TermsPolicyJson = getSettingValue('Terms');
    $TermsTranslations = !empty($TermsPolicyJson) ? json_decode($TermsPolicyJson, true) : [];
@endphp
<div class="row g-3">
    <div class="col-md-6">
        <div class="form-group">
            <div class="mb-4">
                <label class="form-label">{{ __('App Privacy Policy') }}</label>
                <div class="controls">
                    <textarea name="Privacy_Policy[{{ getDefaultLanguage('code') }}]" class="form-control" rows="3"
                        {{ Route::is('*.show') ? 'disabled' : '' }} id="setting-Privacy_Policy" placeholder="{{ __('App Privacy Policy') }}"
                        {{ Route::is('*.create') ? 'required' : '' }} value="{{ getSettingValue('Privacy_Policy') }}">
                    {{ $PrivacyPolicyTranslations[getDefaultLanguage('code')] ?? '' }}
                </textarea>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <div class="mb-4">
                <label class="form-label">{{ __('App Terms') }}</label>
                <div class="controls">
                    <textarea name="Terms[{{ getDefaultLanguage('code') }}]" class="form-control" rows="3"
                        {{ Route::is('*.show') ? 'disabled' : '' }} id="setting-Terms" placeholder="{{ __('App Terms') }}"
                        {{ Route::is('*.create') ? 'required' : '' }} value="{{ getSettingValue('Terms') }}">
                    {{ $TermsTranslations[getDefaultLanguage('code')] ?? '' }}
                </textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        {{ __('Translation Optional') }}
    </div>

    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label for="setting-Privacy_Policy-{{ $language->code }}" class="form-label">
                    Privacy Policy ({{ $language->name }})
                </label>
                <textarea name="Privacy_Policy[{{ $language->code }}]" class="form-control"
                    id="setting-Privacy_Policy-{{ $language->code }}"
                    placeholder="Enter Setting Privacy Policy in {{ $language->name }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    {{ $PrivacyPolicyTranslations[$language->code] ?? '' }}
                </textarea>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="setting-Terms-{{ $language->code }}" class="form-label">
                    Terms ({{ $language->name }})
                </label>
                <textarea name="Terms[{{ $language->code }}]" class="form-control" id="setting-Terms-{{ $language->code }}"
                    placeholder="Enter Setting Terms in {{ $language->name }}" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    {{ $TermsTranslations[$language->code] ?? '' }}
                </textarea>
            </div>
        </div>
    @endforeach
</div>
