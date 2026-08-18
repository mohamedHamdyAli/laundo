@extends('layouts.main')
@section('title')
    {{ __('Home') }}
@endsection
@section('content')
    <section class="section">
        @php
            $hour = now()->hour;
            if ($hour >= 5 && $hour < 12) {
                $greeting = 'Good morning';
            } elseif ($hour >= 12 && $hour < 18) {
                $greeting = 'Good afternoon';
            } else {
                $greeting = 'Good evening';
            }

            $cardStyles = ['total_customer', 'total_items', 'item_for_sale', 'properties_for_rent'];
        @endphp

        <div class="dashboard_title mb-3">{{ __($greeting . ', Admin') }}</div>
        <div class="row mb-3 d-flex">
            <div class="col-md-4 col-sm-12">
                <div class="row">
                    @foreach ($dashboardStats ?? [] as $index => $stat)
                        <div class="col-md-6 col-sm-6 mb-3">
                            @php $cardMarkup = $cardStyles[$index % count($cardStyles)]; @endphp
                            @if (!empty($stat['route']) && Route::has($stat['route']))
                                <a href="{{ route($stat['route']) }}">
                            @endif
                            <div class="card h-100">
                                <div class="{{ $cardMarkup }} d-flex">
                                    <div class="curtain"></div>
                                    <div class="row">
                                        <div class="col-4 col-md-12">
                                            <div class="svg_icon align-items-center d-flex justify-content-center me-3">
                                                <span class="{{ $stat['icon'] }} text-white fa-2x"></span>
                                            </div>
                                        </div>
                                        <div class="col-8 col-md-12">
                                            <div class="total_number">{{ $stat['count'] }}</div>
                                            <div class="card_title">{{ __($stat['title']) }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @if (!empty($stat['route']) && Route::has($stat['route']))
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="col-md-8">
                <div class="hero-3d" x-data="{ rx: 0, ry: 0 }"
                    @mousemove="ry = (($event.offsetX / $event.currentTarget.offsetWidth) - 0.5) * 16; rx = ((0.5 - ($event.offsetY / $event.currentTarget.offsetHeight))) * 16"
                    @mouseleave="rx = 0; ry = 0">
                    <div class="hero-3d-stage" :style="`transform: rotateX(${rx}deg) rotateY(${ry}deg)`">
                        <div class="hero-3d-tile"><i class="bi bi-people"></i></div>
                        <div class="hero-3d-tile"><i class="bi bi-list-task"></i></div>
                        <div class="hero-3d-tile"><i class="bi bi-image"></i></div>
                        <div class="hero-3d-tile"><i class="bi bi-globe2"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
