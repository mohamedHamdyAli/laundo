<div id="sidebar" class="active">
    <div class="sidebar-wrapper active">
        <div class="sidebar-header position-relative">
            <div class="d-block">
                <div class="logo text-center">
                    <a href="{{ url('admin/home') }}">
                        @if (getSettingValue('App_Logo'))
                            <img src="{{ asset('storage/' . getSettingValue('App_Logo')) }}" alt="Logo" width="150">
                        @endif
                    </a>
                </div>
            </div>
        </div>

        <div class="sidebar-menu">
            <ul class="menu">
                <li class="sidebar-item">
                    <a href="{{ url('admin/home') }}" class='sidebar-link'>
                        <i class="bi  bi-house"></i>
                        <span class="menu-item">{{ __('Dashboard') }} </span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Languages') }}</div>

                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.language.index') }}" class='sidebar-link'>
                        <i class="fas fa-language"></i>
                        <span class="menu-item">{{ __('Languages') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Customers') }}</div>

                <li class="sidebar-item">
                    <a href="{{ route('admin.user.index') }}" class='sidebar-link'> <i class="bi bi-people"></i>
                        <span class="menu-item">{{ __('Users') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Banners') }}</div>
                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.banner.index') }}" class='sidebar-link'>
                        <i class="bi bi-image"></i>
                        <span class="menu-item">{{ __('Banners') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Countries') }}</div>
                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.country.index') }}" class='sidebar-link'>
                        <i class="bi bi-globe2"></i>
                        <span class="menu-item">{{ __('Countries') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Cities') }}</div>
                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.city.index') }}" class='sidebar-link'>
                        <i class="bi bi-geo-alt"></i>
                        <span class="menu-item">{{ __('Cities') }}</span>
                    </a>
                </li>




                <div class="sidebar-new-title">{{ __('Categories') }}</div>

                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.category.index') }}" class='sidebar-link'>
                        <i class="bi bi-list-task"></i>
                        <span class="menu-item">{{ __('Categories') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Intros') }}</div>
                <li class="sidebar-item sidebar-submenus">
                    <a href="{{ route('admin.intro.index') }}" class='sidebar-link'>
                        <i class="bi bi-collection-play"></i>
                        <span class="menu-item">{{ __('Intros') }}</span>
                    </a>
                </li>

                <div class="sidebar-new-title">{{ __('Settings') }}</div>
                <li class="sidebar-item has-sub">
                    <a href="#" class='sidebar-link'>
                        <i class="bi bi-gear-fill"></i>
                        <span>{{ __('Settings') }}</span>
                    </a>

                    <ul class="submenu">
                        <li class="submenu-item">
                            <a href="{{ route('admin.generalSetting.viewGeneralSetting') }}">
                                {{ __('General Settings') }}
                            </a>
                        </li>

                        <li class="submenu-item">
                            <a href="{{ route('admin.generalSetting.viewPrivacyAndTerms') }}">
                                {{ __('Privacy And Terms') }}
                            </a>
                        </li>
                    </ul>
                </li>

            </ul>
        </div>
    </div>
</div>
