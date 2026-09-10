@extends('layouts.auth')

{{-- Off `layouts.app` for the same reason as the request page beside it. --}}

@section('title', __('Reset Password'))
@section('heading', __('Set a new password'))
@section('subtitle', __('Choose a password of at least eight characters.'))
@section('tagline', __('The control room'))

@section('form')
    <form method="POST" action="{{ route('password.update') }}" class="hall-fields">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @error('email')
            <p class="hall-note">{{ $message }}</p>
        @enderror

        <div class="hall-field">
            <label class="hall-label" for="email">{{ __('Email address') }}</label>
            <input class="hall-input @error('email') is-wrong @enderror" id="email" name="email" type="email"
                value="{{ $email ?? old('email') }}" required autocomplete="email" autofocus dir="ltr">
        </div>

        <div class="hall-field">
            <label class="hall-label" for="password">{{ __('New password') }}</label>
            <div class="hall-secret">
                <input class="hall-input @error('password') is-wrong @enderror" id="password" name="password"
                    type="password" required autocomplete="new-password" dir="ltr">
                <button class="hall-reveal" type="button" data-reveal-for="password"
                    aria-label="{{ __('Show password') }}">
                    <x-landing.icon name="eye" :size="18" />
                </button>
            </div>
            @error('password')
                <span class="hall-label" style="color: var(--hall-bad)">{{ $message }}</span>
            @enderror
        </div>

        <div class="hall-field">
            <label class="hall-label" for="password-confirm">{{ __('Confirm new password') }}</label>
            <input class="hall-input" id="password-confirm" name="password_confirmation" type="password" required
                autocomplete="new-password" dir="ltr">
        </div>

        <button class="hall-submit" type="submit">{{ __('Reset Password') }}</button>

        <div class="hall-aside">
            <a href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/auth-card.js') }}?v={{ landingAssetVersion('js/auth-card.js') }}" defer></script>
@endpush
