{{--
    The top bar.

    Rewritten rather than patched, because the markup carried four things that
    could not be fixed from CSS:

      * two elements sharing `id="topbarUserDropdown"`, so both menus'
        `aria-labelledby` pointed at the same element and only one was ever
        described correctly
      * a `<button>` nested inside an `<a>` (the language switcher) — a control
        inside a control, which no keyboard can reach sensibly
      * a `<form>` as a direct child of a `<ul>`, which is not valid content for
        a list and gets moved by the parser
      * `&nbsp;&nbsp;` six times over as the spacing between the controls

    The JS hooks that the bundled `app.js` and `notifications.js` reach for are
    kept exactly: `.burger-btn`, `#toggle-dark`, `.theme-icon-moon` / `-sun`,
    `#notificationDropdown`, `#notificationDropdownToggle`, `#notification-badge`,
    `#notification-list`, `#notification-empty`, `.dropdown-btn`, `.flag`,
    `.arrow`.
--}}
<header>
    <nav class="navbar navbar-expand navbar-light topbar">
        <div class="container-fluid topbar-inner">

            {{-- Only shown below the breakpoint where the sidebar is pinned. --}}
            <a href="#" class="burger-btn d-block" aria-label="{{ __('Toggle navigation') }}">
                <i class="bi bi-justify fs-3"></i>
            </a>

            {{-- `topbar-actions` owns the spacing now, with a gap rather than
                 non-breaking spaces. --}}
            <div class="topbar-actions" id="navbarSupportedContent">

                {{-- Language --}}
                <div class="dropdown">
                    @php
                        $shownLanguage = $currentLanguage ?: $defaultLanguage;
                    @endphp
                    <button type="button" class="dropdown-btn dropdown-toggle" id="topbarLanguageDropdown"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Change language') }}">
                        <img src="{{ asset('storage/' . $shownLanguage->icon) }}" alt="{{ $shownLanguage->name }}"
                            id="current-flag" class="flag">
                        <span id="current-language">{{ $shownLanguage->code }}</span>
                        <span class="arrow" aria-hidden="true">&#9662;</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="topbarLanguageDropdown">
                        @foreach ($languages as $language)
                            <li>
                                <a class="dropdown-item" href="{{ route('language.set-current', $language->code) }}">
                                    <img src="{{ asset('storage/' . $language->icon) }}"
                                        alt="{{ $language->name }}" class="flag flag-in-menu">
                                    {{ $language->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Theme. The checkbox is what `app.js` binds to; the label is
                     the control people see. --}}
                <label class="theme-toggle-btn" for="toggle-dark" title="{{ __('Toggle dark mode') }}">
                    <input type="checkbox" id="toggle-dark" class="d-none">
                    <i class="bi bi-moon-stars theme-icon-moon"></i>
                    <i class="bi bi-sun theme-icon-sun"></i>
                </label>

                {{-- Notifications --}}
                <div class="dropdown" id="notificationDropdown">
                    <button type="button" class="topbar-icon-btn" id="notificationDropdownToggle"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Notifications') }}">
                        <i class="bi bi-bell fs-4"></i>
                        <span id="notification-badge" class="badge rounded-pill bg-danger topbar-badge" hidden>0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end topbar-notifications" id="notification-list"
                        aria-labelledby="notificationDropdownToggle">
                        <li class="dropdown-item-text text-muted small" id="notification-empty">
                            {{ __('No notifications') }}
                        </li>
                        {{--
                            A way out of the dropdown. It holds ten items, and
                            an operations alert that scrolled past the tenth
                            used to be unreachable — there was no page listing
                            them.
                        --}}
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center small"
                                href="{{ route('admin.myNotifications.index') }}">
                                {{ __('See all notifications') }}
                            </a>
                        </li>
                    </ul>
                </div>

                {{-- The operator. `topbar-sep` draws the hairline that splits
                     the account control off from the three utilities. --}}
                <div class="dropdown topbar-sep">
                    @php
                        // `profile` is not a column on this table — it is
                        // `image_profile`, so the old `Auth::user()->profile`
                        // read null on every request and the avatar silently
                        // fell back to a stock face for everybody. Same
                        // existence check the dashboard's image helper uses.
                        $avatar = Auth::user()->image_profile;
                        $avatarUrl = ($avatar && Illuminate\Support\Facades\Storage::disk('public')->exists($avatar))
                            ? asset('storage/' . $avatar)
                            : asset('assets/images/faces/2.jpg');
                    @endphp
                    <button type="button" class="topbar-user dropdown-toggle" id="topbarUserDropdown"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="topbar-avatar">
                            <img src="{{ $avatarUrl }}" alt="">
                        </span>
                        <span class="topbar-user-name">{{ Auth::user()->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="topbarUserDropdown">
                        <li>
                            <a class="dropdown-item" href="{{ route('change-password.index') }}">
                                <i class="icon-mid bi bi-gear me-2"></i>{{ __('Change Password') }}
                            </a>
                        </li>
                        {{-- «Change Profile» used to sit here pointing at `#`.
                             There is no dashboard profile route — only the two
                             API ones — so it was a menu item that did nothing,
                             which is worse than one item fewer. --}}
                        <li>
                            <a class="dropdown-item" href="{{ route('logout') }}"
                                onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                <i class="icon-mid bi bi-box-arrow-left me-2"></i>{{ __('Logout') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    {{-- Outside the <ul>: a form is not valid list content, and the parser was
         hoisting it out of the menu anyway. --}}
    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
        @csrf
    </form>
</header>
