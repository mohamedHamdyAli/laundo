@extends('layouts.auth')

{{-- Moved off `layouts.app`, the panel's only Vite chain: it was reachable by
     typing the URL and nothing linked to it, so the missing-manifest 500 was
     invisible. The sign-in screen links here now. --}}

@section('title', __('Reset Password'))
@section('heading', __('Reset your password'))
@section('subtitle', __('Enter your email and we will send you a link to set a new one.'))
@section('tagline', __('The control room'))

@section('form')
    <form method="POST" action="{{ route('password.email') }}" class="hall-fields">
        @csrf

        @error('email')
            <p class="hall-note">{{ $message }}</p>
        @enderror

        <div class="hall-field">
            <label class="hall-label" for="email">{{ __('Email address') }}</label>
            <input class="hall-input @error('email') is-wrong @enderror" id="email" name="email" type="email"
                value="{{ old('email') }}" required autocomplete="email" autofocus dir="ltr">
        </div>

        <button class="hall-submit" type="submit">{{ __('Send Password Reset Link') }}</button>

        <div class="hall-aside">
            <a href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
        </div>
    </form>
@endsection
