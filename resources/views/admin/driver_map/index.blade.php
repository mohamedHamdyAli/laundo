@extends('layouts.main')
@section('content')
    {{--
        «خريطة السواقين» — every driver, where they are, and what they carry.

        The page draws once and then asks `admin.driver_map.data` every few
        seconds; everything that moves comes from there. Zones and laundries
        do not move, so they are handed over here, once.

        Every string the script prints is either one of the translated strings
        below or data run through its own esc() — names, streets and order
        codes are typed by people.
    --}}
    @php
        $mapConfig = [
            'endpoint' => route('admin.driver_map.data'),
            'candidates' => route('admin.driver_map.candidates', ['id' => '__TASK__']),
            'csrf' => csrf_token(),
            'poll' => $pollSeconds,
            'zones' => $zones,
            'laundries' => $laundries,
            'abilities' => $abilities,
            'rtl' => panelIsRtl(),
            'strings' => [
                'on_job' => __('On a job'),
                'available' => __('Available'),
                'unavailable' => __('Unavailable'),
                'inactive' => __('Account suspended'),
                'signal_lost' => __('Signal lost'),
                'late' => __('Running late'),
                'live' => __('Live'),
                'stale' => __('Last seen :time'),
                'none' => __('No location yet'),
                'no_job' => __('Not carrying an order'),
                'due' => __('Due :time'),
                'in_hand' => __('Orders in hand'),
                'of_cap' => __(':held of :cap'),
                'plate' => __('Plate'),
                'areas' => __('Areas'),
                'phone' => __('Phone'),
                'open_driver' => __('Open the driver'),
                'open_order' => __('Open the order'),
                'customer' => __('Customer'),
                'laundry' => __('Laundry'),
                'destination' => __('Going to'),
                'waiting' => __('Waiting for a driver'),
                'legs_waiting' => __('Legs waiting: :count'),
                'who' => __('Who can take it?'),
                'loading' => __('Loading…'),
                'assign' => __('Assign'),
                'rest' => __('And the rest of the order'),
                'km' => __(':km km away'),
                'no_live' => __('No live position'),
                'nobody' => __('Nobody can take this leg right now.'),
                'gone' => __('This leg is not waiting for a driver any more.'),
                'failed' => __('Could not load the drivers. Try again.'),
                'updated' => __('Updated :time'),
                'retrying' => __('Could not refresh the map. Retrying…'),
                'expired' => __('Your session has ended. Reload the page.'),
                'nothing' => __('No driver matches.'),
                'lost_banner' => __('Signal lost while on a job: :count'),
                'zones' => __('Zones'),
                'laundries' => __('Laundries'),
                'waiting_layer' => __('Orders waiting for a driver'),
                'zone_off' => __('Not taking orders'),
            ],
        ];
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Drivers map') }}</h5>
        <span class="driver-map-updated" data-dm-updated aria-live="polite"></span>
    </div>

    <section class="section">
        <div class="driver-map" data-driver-map>
            <aside class="driver-map-side card" aria-label="{{ __('Drivers') }}">
                <div class="driver-map-tools">
                    <input type="search" class="form-control" data-dm-search autocomplete="off"
                        placeholder="{{ __('Search by name, phone, plate or order code') }}"
                        aria-label="{{ __('Search by name, phone, plate or order code') }}">

                    <select class="form-select" data-dm-zone aria-label="{{ __('Area') }}">
                        <option value="">{{ __('Every area') }}</option>
                        @foreach ($zoneOptions as $zone)
                            <option value="{{ $zone['id'] }}">{{ $zone['name'] }}</option>
                        @endforeach
                    </select>

                    <div class="driver-map-chips" role="group" aria-label="{{ __('Show') }}" data-dm-chips>
                        @foreach ([
                            'all' => __('All'),
                            'on_job' => __('On a job'),
                            'available' => __('Available'),
                            'unavailable' => __('Unavailable'),
                            'inactive' => __('Account suspended'),
                            'signal_lost' => __('Signal lost'),
                            'late' => __('Running late'),
                        ] as $key => $label)
                            <button type="button" class="driver-map-chip{{ $key === 'all' ? ' active' : '' }}"
                                data-dm-filter="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}">
                                <span class="dm-dot dm-dot--{{ $key }}" aria-hidden="true"></span>
                                <span>{{ $label }}</span>
                                <span class="driver-map-chip-count" data-dm-count="{{ $key }}">0</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Not `d-flex`: Bootstrap's display utilities are !important
                     and would beat the `hidden` attribute. --}}
                <button type="button" class="driver-map-lost" data-dm-lost hidden></button>

                <ul class="driver-map-list" data-dm-list></ul>
                <p class="driver-map-empty" data-dm-empty hidden>{{ __('No driver matches.') }}</p>
            </aside>

            <div class="driver-map-stage card">
                <div class="driver-map-canvas" data-dm-canvas></div>
                <ul class="driver-map-legend" aria-label="{{ __('Key') }}">
                    <li><span class="dm-dot dm-dot--on_job"></span>{{ __('On a job') }}</li>
                    <li><span class="dm-dot dm-dot--available"></span>{{ __('Available') }}</li>
                    <li><span class="dm-dot dm-dot--unavailable"></span>{{ __('Unavailable') }}</li>
                    <li><span class="dm-dot dm-dot--late"></span>{{ __('Running late') }}</li>
                    <li><span class="dm-dot dm-dot--stale"></span>{{ __('Signal lost') }}</li>
                    @if ($abilities['waiting'])
                        <li><span class="dm-dot dm-dot--waiting"></span>{{ __('Waiting for a driver') }}</li>
                    @endif
                </ul>
            </div>
        </div>
    </section>

    <script type="application/json" id="driver-map-config">@json($mapConfig)</script>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/driver-map.css') }}?v={{ assetVersion('css/driver-map.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('assets/js/custom/driver-map.js') }}?v={{ assetVersion('js/custom/driver-map.js') }}"></script>
@endpush
