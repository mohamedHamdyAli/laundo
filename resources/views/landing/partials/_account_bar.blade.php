{{--
    The way back into the panel, for somebody who is already signed in.

    `/` used to redirect anyone with a session straight to /admin/home, so an
    operator checking how a price renders, or an owner reading the page their
    applicants arrive from, had to sign out to see the site at all. That was the
    page deciding on their behalf; this offers the door instead.

    **Only for an account that has a panel.** `canReachPanel()` is the same test
    `EnsureDashboardRole` refuses people with — customers and drivers are
    API-only, and a «go to your dashboard» button that lands them on a 403 is
    worse than no button. They simply see the site, which is where they belong.

    Both panel role types go to `/admin/home` and that is not a shortcut: the
    home screen builds its panels from the viewer's own permissions, so an
    operator and a laundry owner following the same link arrive at different
    screens. There is no second address to send anyone to.

    Not sticky. The header below it already is, and two stacked bars eat a phone
    screen for a link most people will use once.
--}}
@auth
    @if (auth()->user()->canReachPanel())
        @php
            $account = auth()->user();
        @endphp

        <div class="account-bar" role="region" aria-label="{{ webText('landing.account.region') }}">
            <div class="container account-bar-inner">

                <p class="account-bar-who">
                    <svg class="account-bar-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>

                    {{-- `webText()` prepends the colon itself, so the key here is
                         `name`, not `:name`. Blade escapes the result, which
                         matters because the value is a person's own name. --}}
                    <span>{{ webText('landing.account.signed_in', ['name' => $account->name]) }}</span>

                    @if ($account->role)
                        <span class="account-bar-role">{{ $account->role->name }}</span>
                    @endif
                </p>

                {{-- `.btn-icon` is what `html[dir="rtl"] .btn-icon` already flips,
                     so the arrow points the way the language reads without a
                     second copy of the path. --}}
                <a class="btn btn--primary btn--bar" href="{{ url('/admin/home') }}">
                    {{ webText('landing.account.cta') }}
                    <svg class="btn-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14"/>
                        <path d="m12 5 7 7-7 7"/>
                    </svg>
                </a>

            </div>
        </div>
    @endif
@endauth
