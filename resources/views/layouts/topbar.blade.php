<header>
    <nav class="navbar navbar-expand navbar-light" style="background-color: white;">
        <div class="container-fluid">

            <div class="col-6 row d-flex align-items-center">
                <div class="col-1 me-3 me-md-2">
                    <a href="#" class="burger-btn d-block"><i class="bi bi-justify fs-3"></i></a>

                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                        aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>


            </div>
            <div class="col-6 justify-content-end d-flex">
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <div class="dropdown">
                        <a href="#" id="topbarUserDropdown"
                            class="user-dropdown d-flex align-items-center dropend dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="avatar avatar-md2">
                                <button class="dropdown-btn">
                                    @if ($currentLanguage)
                                        {{-- FIXED: Added asset('storage/' . ...) to display the flag correctly --}}
                                        <img src="{{ asset('storage/' . $currentLanguage->icon) }}"
                                            alt="{{ $currentLanguage->name }}" id="current-flag" class="flag">
                                        <span id="current-language">{{ $currentLanguage->code }}</span>
                                    @else
                                        {{-- FIXED: Added asset('storage/' . ...) to display the flag correctly --}}
                                        <img src="{{ asset('storage/' . $defaultLanguage->icon) }}"
                                            alt="{{ $defaultLanguage->name }}" id="current-flag" class="flag">
                                        <span id="current-language">{{ $defaultLanguage->code }}</span>
                                    @endif
                                    <span class="arrow">&#9662;</span>
                                </button>
                            </div>
                            <div class="text"></div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end topbarUserDropdown"
                            aria-labelledby="topbarUserDropdown">
                            @foreach ($languages as $language)
                                <li>
                                    <a class="dropdown-item"
                                        href="{{ route('language.set-current', $language->code) }}">
                                        <img src="{{ asset('storage/' . $language->icon) }}" alt="{{ $language->name }}"
                                            class="flag" style="width: 20px; margin-right: 5px;">
                                        {{ $language->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    &nbsp;&nbsp;
                    <label class="theme-toggle-btn" for="toggle-dark" title="{{ __('Toggle dark mode') }}">
                        <input type="checkbox" id="toggle-dark" class="d-none">
                        <i class="bi bi-moon-stars theme-icon-moon"></i>
                        <i class="bi bi-sun theme-icon-sun"></i>
                    </label>
                    &nbsp;&nbsp;
                    <div class="dropdown" id="notificationDropdown">
                        <a href="#" id="notificationDropdownToggle" class="user-dropdown d-flex align-items-center"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="avatar avatar-md2 position-relative">
                                <i class="bi bi-bell fs-4"></i>
                                <span id="notification-badge" class="badge rounded-pill bg-danger"
                                    style="display:none;position:absolute;top:-4px;right:-4px;font-size:10px;">0</span>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" id="notification-list"
                            style="min-width:320px;max-height:400px;overflow-y:auto;" aria-labelledby="notificationDropdownToggle">
                            <li class="dropdown-item-text text-muted small" id="notification-empty">{{ __('No notifications') }}</li>
                        </ul>
                    </div>
                    &nbsp;&nbsp;
                    <div class="dropdown">
                        <a href="#" id="topbarUserDropdown"
                            class="user-dropdown d-flex align-items-center dropend dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="avatar avatar-md2">
                                <img src="{{ Auth::user()->profile == '' ? url('assets/images/faces/2.jpg') : Auth::user()->profile }} "
                                    alt="">
                            </div>
                            <div class="text">
                                <h6 class="user-dropdown-name">{{ Auth::user()->name }}</h6>
                                <p class="user-dropdown-status text-sm text-muted"></p>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end topbarUserDropdown"
                            aria-labelledby="topbarUserDropdown">
                            <li><a class="dropdown-item" href="{{ route('change-password.index') }}"><i
                                        class="icon-mid bi bi-gear me-2"></i>{{ __('Change Password') }}</a></li>
                            <li><a class="dropdown-item" href="#"><i
                                        class="icon-mid bi bi-person me-2"></i>{{ __('Change Profile') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('logout') }} "
                                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><i
                                        class="icon-mid bi bi-box-arrow-left me-2"></i> {{ __('Logout') }}</a></li>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                {{ csrf_field() }}
                            </form>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</header>
