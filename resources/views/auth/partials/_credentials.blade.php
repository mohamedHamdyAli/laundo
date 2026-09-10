{{-- The sign-in form itself.

     Its markup is its own rather than Bootstrap's, because this page no longer
     loads the panel's stylesheet — it was pulling in `app.css`, `auth.css` and
     `theme.css` to draw two inputs and a button, and taking a cyan
     `btn-primary` belonging to no palette in this application along with them.

     Both doors post to `route('login')`: the throttling, the session and the
     redirect to /admin/home are Laravel's own, and a second controller would be
     a second place for them to drift. --}}
<form method="POST" action="{{ route('login') }}" id="frmLogin" class="hall-fields">
    @csrf

    @error('email')
        <p class="hall-note">{{ $message }}</p>
    @enderror

    <div class="hall-field">
        <label class="hall-label" for="email">{{ __('Email address') }}</label>
        <input class="hall-input @error('email') is-wrong @enderror" id="email" name="email" type="email"
            value="{{ old('email') }}" required autocomplete="email" autofocus dir="ltr">
    </div>

    <div class="hall-field">
        <label class="hall-label" for="password">{{ __('Password') }}</label>
        <div class="hall-secret">
            <input class="hall-input @error('password') is-wrong @enderror" id="password" name="password"
                type="password" required autocomplete="current-password" dir="ltr">
            <button class="hall-reveal" type="button" data-reveal-for="password"
                aria-label="{{ __('Show password') }}">
                <x-landing.icon name="eye" :size="18" />
            </button>
        </div>
        @error('password')
            <span class="hall-label" style="color: var(--hall-bad)">{{ $message }}</span>
        @enderror
    </div>

    <button class="hall-submit" type="submit">{{ __('Sign In') }}</button>

    @if (Route::has('password.request'))
        <div class="hall-aside">
            <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
        </div>
    @endif
</form>

@push('scripts')
    <script src="{{ asset('assets/js/auth-card.js') }}?v={{ landingAssetVersion('js/auth-card.js') }}" defer></script>
@endpush
