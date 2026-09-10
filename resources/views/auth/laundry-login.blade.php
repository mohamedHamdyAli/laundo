@extends('layouts.auth-card')

{{-- The laundry's own door.

     Not a second authentication path — the form posts to `route('login')` like
     the admin one, so throttling, the session and the redirect to /admin/home
     stay in one place. What differs is everything around it: an owner arriving
     at a page captioned «Admin Control Panel» has no way to tell whether the
     account they were given belongs there. --}}

@section('title', __('Laundry Sign In'))
@section('heading', __('Your laundry, signed in'))
@section('subtitle', __('Use the email and password you registered with.'))

@section('form')
    @if ($errors->any())
        <p class="auth-note is-bad">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="auth-grid">
            <div class="auth-field">
                <label class="auth-label" for="email">{{ __('Email address') }}</label>
                <input class="auth-input @error('email') has-error @enderror" id="email" name="email" type="email"
                    value="{{ old('email') }}" required autocomplete="email" autofocus
                    placeholder="{{ __('name@laundry.com') }}">
            </div>

            <div class="auth-field">
                <label class="auth-label" for="password">{{ __('Password') }}</label>
                <div class="auth-password">
                    <input class="auth-input" id="password" name="password" type="password" required
                        autocomplete="current-password" placeholder="{{ __('Password') }}">
                    <button class="auth-reveal" type="button" data-reveal-for="password"
                        aria-label="{{ __('Show password') }}">
                        <x-landing.icon name="eye" :size="18" />
                    </button>
                </div>
            </div>
        </div>

        <button class="auth-submit" type="submit">{{ __('Sign In') }}</button>

        <div class="auth-links">
            <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
            <a href="{{ route('laundry.register') }}">{{ __('Register your laundry') }}</a>
        </div>
    </form>
@endsection

@section('footnote')
    {{ __('New to Laundo?') }}
    <a href="{{ route('laundry.register') }}">{{ __('Apply to join') }}</a>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/auth-card.js') }}?v={{ landingAssetVersion('js/auth-card.js') }}" defer></script>
@endpush
