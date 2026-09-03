@php
    $privacyTranslations = json_decode((string) getSettingValue('Privacy_Policy'), true) ?: [];
    $termsTranslations = json_decode((string) getSettingValue('Terms'), true) ?: [];
    $defaultCode = getDefaultLanguage('code');
@endphp

{{--
    Two things were wrong with these fields, both invisible until somebody saved.

    Each `<textarea>` carried `value="{{ getSettingValue(...) }}"` — an attribute
    a textarea does not have, holding the raw JSON of *every* language, which
    rendered the whole blob into the page's HTML.

    And the content sat on its own indented line, so everything between the tags
    — a newline and twenty spaces before the text, a newline and sixteen after —
    was part of the value. Every save folded that in and the next save added it
    again. The text is flush against the tags now, and the service trims on the
    way in as well.
--}}
<div class="row g-3">
    <div class="col-12">
        <div class="form-group">
            <label for="setting-terms" class="form-label">{{ __('App Terms') }}</label>
            <div class="controls">
                <textarea name="Terms[{{ $defaultCode }}]" id="setting-terms" class="form-control" rows="16"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('App Terms') }}">{{ $termsTranslations[$defaultCode] ?? '' }}</textarea>
                <div class="form-text">
                    {{ __('Shown in the app under «Terms and Conditions», and linked from the sign-up screen.') }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="setting-privacy" class="form-label">{{ __('App Privacy Policy') }}</label>
            <div class="controls">
                <textarea name="Privacy_Policy[{{ $defaultCode }}]" id="setting-privacy" class="form-control" rows="16"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('App Privacy Policy') }}">{{ $privacyTranslations[$defaultCode] ?? '' }}</textarea>
                <div class="form-text">
                    {{ __('Shown in the app under «Privacy Policy», and linked from the sign-up screen.') }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- The legend and rule the rest of the panel uses, instead of a bare line of
     text with nothing to say that what follows is the same fields again. --}}
<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Translation') }}</div>
</div>

<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-12">
            <div class="form-group">
                <label for="setting-terms-{{ $language->code }}" class="form-label">
                    {{ __('App Terms') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="Terms[{{ $language->code }}]" id="setting-terms-{{ $language->code }}"
                        class="form-control" rows="16" {{ Route::is('*.show') ? 'disabled' : '' }}
                        placeholder="{{ __('App Terms') }} ({{ $language->name }})">{{ $termsTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="form-group">
                <label for="setting-privacy-{{ $language->code }}" class="form-label">
                    {{ __('App Privacy Policy') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="Privacy_Policy[{{ $language->code }}]" id="setting-privacy-{{ $language->code }}"
                        class="form-control" rows="16" {{ Route::is('*.show') ? 'disabled' : '' }}
                        placeholder="{{ __('App Privacy Policy') }} ({{ $language->name }})">{{ $privacyTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>
