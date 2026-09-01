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
                {{--
                    brandLogo(), not the raw setting: it shows what is actually
                    being used. This install carried App_Logo = 'logo1.png' from
                    the template with no such file, so the preview here was a
                    broken image while every screen fell back to the default.
                --}}
                <a href='{{ brandLogo('dark') }}'>
                    <img class="rounded mt-2" style="height: 80px; width: 80px; object-fit: contain;"
                        src="{{ brandLogo('dark') }}" alt="{{ __('App Logo') }}">
                </a>
            </div>
        </div>
    </div>
    {{--
        The light logo.

        Two files rather than one, because the brand mark is navy: on the navy
        sidebar it measures 1.08:1, which is not "hard to read", it is gone. This
        is the same artwork in white. Optional — a bundled default covers it —
        but a replaced App_Logo needs its pair replaced too, or the sidebar goes
        blank again and nothing says why.
    --}}
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('App Logo (light, for dark backgrounds)') }}</label>
            <div class="controls">
                <input type="file" name="App_Logo_Light" class="form-control">
                <a href='{{ brandLogo('light') }}'>
                    {{-- On a dark tile: a white logo previewed on white is a white square. --}}
                    <img class="rounded mt-2" style="height: 80px; width: 80px; background: #0f2d52; object-fit: contain; padding: 6px;"
                        src="{{ brandLogo('light') }}" alt="{{ __('App Logo (light, for dark backgrounds)') }}">
                </a>
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

{{-- «ادعُ أصدقاءك» --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('Referrals') }}</h5>
    <p class="text-muted small">
        {{ __('Both the inviter and the friend get this discount, once the friend has paid for their first order. Leave the value empty to run no referral programme.') }}
    </p>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Reward type') }}</label>
            <div class="controls">
                <select name="Referral_Reward_Type" class="form-control">
                    <option value="percentage" {{ getSettingValue('Referral_Reward_Type') !== 'fixed' ? 'selected' : '' }}>
                        {{ __('Percentage') }}
                    </option>
                    <option value="fixed" {{ getSettingValue('Referral_Reward_Type') === 'fixed' ? 'selected' : '' }}>
                        {{ __('Fixed amount') }}
                    </option>
                </select>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Reward value') }}</label>
            <div class="controls">
                <input type="number" step="0.01" min="0" name="Referral_Reward_Value" class="form-control"
                    placeholder="0" value="{{ getSettingValue('Referral_Reward_Value') }}">
            </div>
        </div>
    </div>
</div>

{{-- Region / Timezone --}}
<div class="row g-1 border rounded p-3 mb-3">
    <h5 class="mb-3">{{ __('Region') }}</h5>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Country') }}</label>
            <div class="controls">
                <select name="Country_Id" class="form-control">
                    <option value="">{{ __('Select Country') }}</option>
                    @foreach (\App\Modules\Country\Models\Country::where('status', 'active')->get() as $country)
                        <option value="{{ $country->id }}"
                            {{ getSettingValue('Country_Id') == $country->id ? 'selected' : '' }}>
                            {{ getLocalizedValueDashboard($country, 'name') }}
                            @if ($country->timezone)
                                ({{ $country->timezone }})
                            @endif
                        </option>
                    @endforeach
                </select>
                <div class="form-text mt-2">{{ __('The app uses this country\'s timezone for displaying dates and times.') }}</div>
            </div>
        </div>
    </div>
</div>
