@extends('layouts.auth-card')

{{-- «سجّل مغسلتك» — the public application.

     Everything the panel's own create screen asks for, because the point of
     collecting it now is that an operator can decide without a phone call. The
     one thing it does not carry is `status`: an application is filed switched
     off, and only approval turns it on. --}}

@section('title', __('Register your laundry'))
@section('card-modifier', 'is-wide')
@section('heading', __('Register your laundry'))
@section('subtitle', __('Fill this in and our team will review it. You will be able to sign in as soon as it is approved.'))

@section('form')
    @if ($errors->any())
        <p class="auth-note is-bad">{{ __('Please check the fields marked below.') }}</p>
    @endif

    <form method="POST" action="{{ route('laundry.register.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="auth-grid">
            <p class="auth-legend is-full">{{ __('The laundry') }}</p>

            {{-- The default language first, then the rest — the same shape as
                 the panel's own form. Only one of them has to be filled: a
                 laundry writing Arabic only is normal, and the request enforces
                 "at least one" rather than "all". --}}
            <div class="auth-field">
                <label class="auth-label" for="name-default">
                    {{ __('Laundry name') }} ({{ getDefaultLanguage('name') }})
                    <span class="is-required">*</span>
                </label>
                <input class="auth-input @error('name') has-error @enderror" id="name-default"
                    name="name[{{ getDefaultLanguage('code') }}]" type="text"
                    value="{{ old('name.'.getDefaultLanguage('code')) }}" maxlength="191">
            </div>

            @foreach (getAllLanguageWithoutDefault() as $language)
                <div class="auth-field">
                    <label class="auth-label" for="name-{{ $language->code }}">
                        {{ __('Laundry name') }} ({{ $language->name }})
                    </label>
                    <input class="auth-input" id="name-{{ $language->code }}"
                        name="name[{{ $language->code }}]" type="text"
                        value="{{ old('name.'.$language->code) }}" maxlength="191">
                </div>
            @endforeach

            @error('name')
                <p class="auth-error is-full">{{ $message }}</p>
            @enderror

            <div class="auth-field">
                <label class="auth-label" for="phone">
                    {{ __('Laundry phone') }} <span class="is-required">*</span>
                </label>
                <input class="auth-input @error('phone') has-error @enderror" id="phone" name="phone" type="text"
                    value="{{ old('phone') }}" required placeholder="+201012345678" dir="ltr">
                @error('phone')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="email">{{ __('Laundry email') }}</label>
                <input class="auth-input @error('email') has-error @enderror" id="email" name="email" type="email"
                    value="{{ old('email') }}" placeholder="{{ __('Optional') }}">
                @error('email')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="city_id">
                    {{ __('City') }} <span class="is-required">*</span>
                </label>
                <select class="auth-select @error('city_id') has-error @enderror" id="city_id" name="city_id" required>
                    <option value="">{{ __('Choose a city') }}</option>
                    @foreach ($cities as $city)
                        {{-- data-lat / data-lng is what x-map-picker reads to
                             recentre when a city is chosen. --}}
                        <option value="{{ $city->id }}" @selected(old('city_id') == $city->id)
                            data-lat="{{ $city->lat }}" data-lng="{{ $city->lng }}">
                            {{ getLocalizedValue($city, 'name') }}
                        </option>
                    @endforeach
                </select>
                @error('city_id')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="logo">{{ __('Logo') }}</label>
                <input class="auth-input @error('logo') has-error @enderror" id="logo" name="logo" type="file"
                    accept="image/*">
                @error('logo')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field is-full">
                <label class="auth-label" for="address">{{ __('Address') }}</label>
                <textarea class="auth-textarea @error('address') has-error @enderror" id="address" name="address"
                    maxlength="1000" placeholder="{{ __('Street, building, landmark') }}">{{ old('address') }}</textarea>
                @error('address')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- The panel's own picker, not a second one.

                 `x-map-picker` already drives the city and laundry forms: it
                 recentres on the chosen city, searches places through
                 Nominatim, and writes the two coordinate boxes. A hand-rolled
                 map here would be a second implementation of a solved thing,
                 and `MapPickerTest` would not be watching it.

                 Required, not optional: delivery is priced by distance from
                 this pin, so a laundry without one cannot be given an order
                 even after it is approved. --}}
            <div class="auth-field is-full">
                <x-map-picker lat-input="lat" lng-input="lng" city-select="city_id" inputs="hidden"
                    :label="__('Where you are on the map')"
                    :hint="__('Search or tap your location. Delivery fees are measured from this point.')" />
                <input type="hidden" id="lat" name="lat" value="{{ old('lat') }}">
                <input type="hidden" id="lng" name="lng" value="{{ old('lng') }}">
                @error('lat')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <p class="auth-legend is-full">{{ __('Your account') }}</p>

            <div class="auth-field">
                <label class="auth-label" for="owner_name">
                    {{ __('Your name') }} <span class="is-required">*</span>
                </label>
                <input class="auth-input @error('owner_name') has-error @enderror" id="owner_name" name="owner_name"
                    type="text" value="{{ old('owner_name') }}" required>
                @error('owner_name')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="owner_phone">
                    {{ __('Your phone') }} <span class="is-required">*</span>
                </label>
                <input class="auth-input @error('owner_phone') has-error @enderror" id="owner_phone" name="owner_phone"
                    type="text" value="{{ old('owner_phone') }}" required placeholder="+201012345678" dir="ltr">
                @error('owner_phone')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field is-full">
                <label class="auth-label" for="owner_email">
                    {{ __('Your email') }} <span class="is-required">*</span>
                </label>
                <input class="auth-input @error('owner_email') has-error @enderror" id="owner_email" name="owner_email"
                    type="email" value="{{ old('owner_email') }}" required>
                <span class="auth-hint">{{ __('This is what you will sign in with.') }}</span>
                @error('owner_email')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="owner_password">
                    {{ __('Password') }} <span class="is-required">*</span>
                </label>
                <div class="auth-password">
                    <input class="auth-input @error('owner_password') has-error @enderror" id="owner_password"
                        name="owner_password" type="password" required autocomplete="new-password">
                    <button class="auth-reveal" type="button" data-reveal-for="owner_password"
                        aria-label="{{ __('Show password') }}">
                        <x-landing.icon name="eye" :size="18" />
                    </button>
                </div>
                <span class="auth-hint">{{ __('At least 8 characters.') }}</span>
                @error('owner_password')
                    <span class="auth-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="auth-field">
                <label class="auth-label" for="owner_password_confirmation">
                    {{ __('Confirm password') }} <span class="is-required">*</span>
                </label>
                <div class="auth-password">
                    <input class="auth-input" id="owner_password_confirmation" name="owner_password_confirmation"
                        type="password" required autocomplete="new-password">
                    <button class="auth-reveal" type="button" data-reveal-for="owner_password_confirmation"
                        aria-label="{{ __('Show password') }}">
                        <x-landing.icon name="eye" :size="18" />
                    </button>
                </div>
            </div>

            <label class="auth-check is-full">
                <input type="checkbox" name="accepts_terms" value="1" @checked(old('accepts_terms')) required>
                <span>
                    {!! __('I agree to the :terms and the :privacy.', [
                        'terms' => '<a href="'.route('landing.localised.terms', ['locale' => panelLanguageCode()]).'" target="_blank" rel="noopener">'.__('Terms and Conditions').'</a>',
                        'privacy' => '<a href="'.route('landing.localised.privacy', ['locale' => panelLanguageCode()]).'" target="_blank" rel="noopener">'.__('Privacy Policy').'</a>',
                    ]) !!}
                </span>
            </label>
            @error('accepts_terms')
                <span class="auth-error is-full">{{ $message }}</span>
            @enderror
        </div>

        <button class="auth-submit" type="submit">{{ __('Submit application') }}</button>
    </form>
@endsection

@section('footnote')
    {{ __('Already registered?') }}
    <a href="{{ route('laundry.login') }}">{{ __('Sign in') }}</a>
@endsection

{{-- Prepended, not pushed: the map component queued its own script when it
     rendered further up the page, and Leaflet has to be defined before it
     runs. --}}
@prepend('scripts')
    {{-- The bundled copy, not a CDN: this form collects a password, and the
         panel already ships Leaflet for its own picker. --}}
    <script src="{{ asset('assets/js/leaflet.js') }}"></script>
@endprepend

@prepend('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/leaflet.css') }}">
@endprepend

@push('scripts')
    <script src="{{ asset('assets/js/auth-card.js') }}?v={{ landingAssetVersion('js/auth-card.js') }}" defer></script>
@endpush
