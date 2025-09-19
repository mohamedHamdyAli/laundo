@php
    $aboutJson = getSettingValue('About');
    $aboutTranslations = !empty($aboutJson) ? json_decode($aboutJson, true) : [];
@endphp

{{-- General Setting --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('General Setting') }}</h5>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('APP Name') }}</label>
            <div class="controls">
                <input type="text" name="App_Name" class="form-control" placeholder="{{ __('APP Name') }}"
                    value="{{ getSettingValue('App_Name') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('App Logo') }}</label>
            <div class="controls">
                <input type="file" name="App_Logo" class="form-control"
                    {{ Route::is('*.create') ? 'required' : '' }}>
                @if (getSettingValue('App_Logo'))
                    <a href='{{ getImageassetUrl(getSettingValue('App_Logo')) }}'>
                        <img class="rounded" style="height: 80px; width:80px;"
                            src="{{ getImageassetUrl(getSettingValue('App_Logo')) }}" alt="flag Image">
                    </a>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Image Login Background') }}</label>
            <div class="controls">
                <input type="file" name="Login_Cover" class="form-control"
                    {{ Route::is('*.create') ? 'required' : '' }}>
                @if (getSettingValue('Login_Cover'))
                    <a href='{{ getImageassetUrl(getSettingValue('Login_Cover')) }}'>
                        <img class="rounded" style="height: 80px; width:80px;"
                            src="{{ getImageassetUrl(getSettingValue('Login_Cover')) }}" alt="flag Image">
                    </a>
                @endif
            </div>
        </div>
    </div>
    <div class="col-12 mt-3">
        <strong>{{ __('Translation') }}</strong>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="setting-About" class="form-label">{{ __('App About') }}</label>
                <div class="controls">
                    <textarea name="About[{{ getDefaultLanguage('code') }}]" class="form-control" rows="3"
                        {{ Route::is('*.show') ? 'disabled' : '' }} id="setting-title" placeholder="{{ __('Enter App About') }}"
                        {{ Route::is('*.create') ? 'required' : '' }}>{{ $aboutTranslations[getDefaultLanguage('code')] ?? '' }}</textarea>
                </div>
            </div>
        </div>

        @foreach (getAllLanguageWithoutDefault() as $language)
            <div class="col-md-6">
                <div class="form-group">
                    <label for="setting-About-{{ $language->code }}" class="form-label">
                        About ({{ $language->name }})
                    </label>
                    <textarea name="About[{{ $language->code }}]" class="form-control" id="setting-About-{{ $language->code }}"
                        placeholder="Enter Setting About in {{ $language->name }}" {{ Route::is('*.show') ? 'disabled' : '' }}
                        rows="3">{{ $aboutTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Social Setting --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('Social Setting') }}</h5>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Whats App') }}</label>
            <div class="controls">
                <input type="text" name="Whats_App" class="form-control" placeholder="{{ __('App Whats App') }}"
                    value="{{ getSettingValue('Whats_App') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Facebook Url') }}</label>
            <div class="controls">
                <input type="text" name="Facebook_Url" class="form-control"
                    placeholder="{{ __('App Facebook Url') }}" value="{{ getSettingValue('Facebook_Url') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Twitter Url') }}</label>
            <div class="controls">
                <input type="text" name="Twitter_Url" class="form-control" placeholder="{{ __('App Twitter Url') }}"
                    value="{{ getSettingValue('Twitter_Url') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Instagram Url') }}</label>
            <div class="controls">
                <input type="text" name="Instagram_Url" class="form-control"
                    placeholder="{{ __('App Instagram Url') }}" value="{{ getSettingValue('Instagram_Url') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Linkedin Url') }}</label>
            <div class="controls">
                <input type="text" name="Linkedin_Url" class="form-control"
                    placeholder="{{ __('App Linkedin Url') }}" value="{{ getSettingValue('Linkedin_Url') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Youtube Url') }}</label>
            <div class="controls">
                <input type="text" name="Youtube_Url" class="form-control"
                    placeholder="{{ __('App Youtube Url') }}" value="{{ getSettingValue('Youtube_Url') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Snapchat Url') }}</label>
            <div class="controls">
                <input type="text" name="Snapchat_Url" class="form-control"
                    placeholder="{{ __('App Snapchat Url') }}" value="{{ getSettingValue('Snapchat_Url') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('App Gmail Url') }}</label>
            <div class="controls">
                <input type="text" name="Gmail_Url" class="form-control" placeholder="{{ __('App Gmail Url') }}"
                    value="{{ getSettingValue('Gmail_Url') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
</div>

{{-- Contact US --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('Contact Us') }}</h5>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Hotline') }}</label>
            <div class="controls">
                <input type="text" name="Hotline" class="form-control" placeholder="{{ __('Enter Hotline') }}"
                    value="{{ getSettingValue('Hotline') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Call') }}</label>
            <div class="controls">
                <input type="text" name="Call" class="form-control" placeholder="{{ __('Enter Call') }}"
                    value="{{ getSettingValue('Call') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Email') }}</label>
            <div class="controls">
                <input type="Email" name="Email" class="form-control" placeholder="{{ __('Enter Email') }}"
                    value="{{ getSettingValue('Email') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
</div>

{{-- App Tax --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('App Tax') }}</h5>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('App Tax') }}</label>
            <div class="controls">
                <input type="text" name="Tax" class="form-control" placeholder="{{ __('Enter App Tax') }}"
                    value="{{ getSettingValue('Tax') }}" {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
</div>
