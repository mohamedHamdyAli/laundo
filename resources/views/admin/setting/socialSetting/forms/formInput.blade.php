{{-- WHATS_App / FACEBOOK_URL / TWITTER_URL / INSTAGRAM_URL --}}

<div class="row g-3">
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
</div>
{{-- LINKEDIN_URL / YOUTUBE_URL / SNAPCHAT_URL / GMAIL_URL --}}

<div class="row g-3">
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
                <input type="text" name="Youtube_Url" class="form-control" placeholder="{{ __('App Youtube Url') }}"
                    value="{{ getSettingValue('Youtube_Url') }}" {{ Route::is('*.create') ? 'required' : '' }}>
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
