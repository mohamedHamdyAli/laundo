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
                    <a href="{{ route('home') }}" class="sidebar-link">
                        <i class="bi bi-house"></i>
                        <span>{{ __('Dashboard') }}</span>
                    </a>
                </li>

                @foreach ($dynamicMenu as $item)
                    {{-- Dropdown Group --}}
                    @if ($item['type'] === 'group')
                        <li class="sidebar-item has-sub">
                            <a href="#" class="sidebar-link">
                                <i class="{{ $item['icon'] }}"></i>
                                <span>{{ __($item['title']) }}</span>
                            </a>

                            <ul class="submenu">
                                @foreach ($item['items'] as $sub)
                                    <li class="submenu-item">
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
                        <li class="sidebar-item">
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
