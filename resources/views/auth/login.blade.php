@php
    $currentLangCode = Session::get('language')?->code ?? 'en';
    $loginCover = getSettingValue('Login_Cover')
        ? asset('storage/' . getSettingValue('Login_Cover'))
        : null;
    // Two panels, two backgrounds, two variants. brandLogo() decides; a template
    // choosing for itself is how one of them ends up navy on navy.
    $appLogoDark = brandLogo('dark');
    $appLogoLight = brandLogo('light');
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLangCode }}" dir="{{ $currentLangCode === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- The bundled favicon was the vendor template's own logo — a cyan
         magnifying glass, another product's brand mark, sitting in the tab of
         every page. `laundo-mark.png` is the leading letterform of the Laundo
         wordmark, cropped square and padded so it still reads at 16px, where
         the wide wordmark is only a smear.

         `type="image/png"`, not `image/x-icon`: the file was always a PNG, and
         some browsers ignore a link whose declared type does not match and fall
         back to /favicon.ico — which ships empty here, hence the blank tab. --}}
    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">
    <title>{{ __('Login') }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    @if ($currentLangCode === 'ar')
        <link rel="stylesheet" href="{{ asset('assets/css/main/rtl.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/main/app.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('assets/css/pages/auth.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/theme.css') }}">
    <script src="{{ asset('assets/js/jquery.min.js') }}"></script>
</head>

<body>
    <div class="login-split">
        <div class="login-form-panel">
            <div class="login-form-inner">
                <div class="mb-4">
                    {{-- White panel, so the navy mark. --}}
                    <img src="{{ $appLogoDark }}" class="login-form-logo"
                        alt="{{ getSettingValue('App_Name') ?: config('app.name') }}">
                </div>

                <h3>{{ __('Welcome back') }}</h3>
                <p class="login-subtitle">{{ __('Sign in to your admin account to continue') }}</p>

                <form method="POST" action="{{ route('login') }}" id="frmLogin">
                    @csrf
                    <div class="form-group position-relative mb-3">
                        <label for="email" class="form-label">{{ __('Email address') }}</label>
                        <input id="email" type="email" placeholder="{{ __('Email') }}"
                            class="form-control @error('email') is-invalid @enderror" name="email"
                            value="{{ old('email') }}" required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="form-group position-relative mb-4" id="pwd">
                        <label for="password" class="form-label">{{ __('Password') }}</label>
                        <div class="input-group">
                            <input id="password" type="password" placeholder="{{ __('Password') }}"
                                class="form-control @error('password') is-invalid @enderror" name="password"
                                required autocomplete="current-password">
                            <span class="input-group-text" id="toggle_pass" role="button">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                        @error('password')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    {{-- `btn-gold` was the name of a brand colour this panel no
                         longer uses; theme.css had been repainting it blue under
                         a gold name ever since. --}}
                    <button class="btn btn-primary w-100" type="submit">{{ __('Sign In') }}</button>
                </form>
            </div>
        </div>

        <div class="login-brand-panel">
            {{-- Navy panel, so the white mark.

                 The pill under the logo repeated the app's name, which the
                 wordmark beside it already says — and the four icon bubbles
                 below (people, list, image, globe) were decoration with no
                 labels and no relation to anything on the screen. Both gone:
                 what is left is the mark and what this panel is for.

                 `getSettingValue('App_Name')` rather than `config('app.name')`
                 for the alt text, matching the sidebar — the config value is
                 still the framework default in this install. --}}
            <img src="{{ $appLogoLight }}" class="brand-logo"
                alt="{{ getSettingValue('App_Name') ?: config('app.name') }}">
            <p class="brand-tagline">{{ __('Admin Control Panel') }}<br>{{ __('Manage your platform with ease') }}</p>
        </div>
    </div>

    @if ($loginCover)
        <style>
            .login-brand-panel {
                background-image: linear-gradient(160deg, rgba(16, 24, 40, .85), rgba(28, 43, 74, .9)), url('{{ $loginCover }}');
                background-size: cover;
                background-position: center;
            }
        </style>
    @endif

    <script>
        $("#toggle_pass").on('click', function() {
            $(this).find('i').toggleClass("bi-eye bi-eye-slash");
            let input = $('[name="password"]');
            input.attr("type", input.attr("type") === "password" ? "text" : "password");
        });
    </script>
</body>

</html>
