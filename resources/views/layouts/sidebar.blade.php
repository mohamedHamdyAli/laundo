<div id="sidebar" class="active">
    <div class="sidebar-wrapper active">
        {{-- `position-relative` was carrying a `!important` that beat the sticky
             positioning in theme.css, and nothing inside this header is
             absolutely positioned, so it was doing no work. --}}
        <div class="sidebar-header">
            <div class="d-block">
                <div class="logo text-center">
                    <a href="{{ url('admin/home') }}">
                        {{--
                            The LIGHT variant: this panel is navy, and the brand
                            mark is navy too — 1.08:1, invisible. brandLogo()
                            falls back to the bundled asset, so there is no @if
                            here; an empty setting used to leave a blank box with
                            nothing to say why.
                        --}}
                        <img src="{{ brandLogo('light') }}" alt="{{ getSettingValue('App_Name') ?: config('app.name') }}">
                    </a>
                </div>
            </div>
        </div>

        <div class="sidebar-menu">
            <ul class="menu">

                @php
                    $routeIsActive = function (string $routeName) {
                        if (!Illuminate\Support\Facades\Route::has($routeName)) {
                            return false;
                        }
                        $pattern = str_contains($routeName, '.')
                            ? Illuminate\Support\Str::beforeLast($routeName, '.') . '.*'
                            : $routeName;
                        return request()->routeIs($pattern);
                    };
                @endphp

                <li class="sidebar-item {{ request()->routeIs('home') ? 'active' : '' }}">
                    <a href="{{ route('home') }}" class="sidebar-link">
                        <i class="bi bi-house"></i>
                        <span>{{ __('Dashboard') }}</span>
                    </a>
                </li>

                @foreach ($dynamicMenu as $item)
                    {{-- Dropdown Group --}}
                    @if ($item['type'] === 'group')
                        @php
                            $groupActive = collect($item['items'])->contains(fn ($sub) => $routeIsActive($sub['route']));
                        @endphp
                        <li class="sidebar-item has-sub {{ $groupActive ? 'active' : '' }}">
                            <a href="#" class="sidebar-link">
                                <i class="{{ $item['icon'] }}"></i>
                                <span>{{ __($item['title']) }}</span>
                            </a>

                            <ul class="submenu {{ $groupActive ? 'active' : '' }}">
                                @foreach ($item['items'] as $sub)
                                    <li class="submenu-item {{ $routeIsActive($sub['route']) ? 'active' : '' }}">
                                        <a href="{{ route($sub['route']) }}">
                                            <i class="{{ $sub['icon'] }} me-2"></i>
                                            {{ __($sub['title']) }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </li>

                        {{-- Single Item --}}
                    @else
                        <li class="sidebar-item {{ $routeIsActive($item['route']) ? 'active' : '' }}">
                            <a href="{{ route($item['route']) }}" class="sidebar-link">
                                <i class="{{ $item['icon'] }}"></i>
                                <span>{{ __($item['title']) }}</span>
                            </a>
                        </li>
                    @endif
                @endforeach


            </ul>

        </div>
    </div>
</div>
