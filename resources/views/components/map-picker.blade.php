@props([
    // The two inputs this map drives. Ids, not names, because the map has to
    // find them from anywhere on the page — the coordinate boxes sit in a
    // different column from the map on the laundry form.
    'latInput',
    'lngInput',

    // A city <select> whose options carry data-lat / data-lng. Optional: a form
    // that has coordinates but no city (the city form itself) simply omits it
    // and the map keeps whatever centre it opened on.
    'citySelect' => null,

    // What to do with the two coordinate boxes once the map is driving them:
    //   visible  — leave them alone, typeable (the default)
    //   readonly — show the numbers, but the map is the only way to change them
    //   hidden   — take the whole field out of the form; the map is the input
    // Applied to the fields' own wrappers, so the label goes with the box.
    'inputs' => 'visible',

    // A place search over OpenStreetMap's Nominatim. Off for a read-only map,
    // where there is nothing to move.
    'search' => true,

    // Where to look when there is nothing else to go on — no pin dropped yet
    // and no city chosen. Egypt, because that is the country this install runs
    // in; read from the Country_Id setting would be the same answer with more
    // moving parts and a null to handle.
    'defaultLat' => 26.8206,
    'defaultLng' => 30.8025,
    'defaultZoom' => 5,

    // Zoom once a city is picked: close enough to see districts, wide enough
    // that the whole governorate is still reachable by dragging.
    'cityZoom' => 11,
    // Zoom when an actual pin exists — this is a specific building.
    'pinZoom' => 15,

    'height' => '360px',
    'readonly' => false,
    'label' => null,
    'hint' => null,
])

@php
    // One id per instance, so two pickers on one page cannot collide.
    $mapId = 'map-picker-'.Str::random(8);
    $showSearch = $search && ! $readonly;
@endphp

<div class="map-picker" data-map-picker>
    @if ($label)
        <label class="form-label">{{ $label }}</label>
    @endif

    @if ($showSearch)
        {{-- Not a <form>: this sits inside the module's own form, and a nested
             one is invalid markup that submits the wrong thing on Enter. --}}
        <div class="map-picker-search">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" data-map-search
                    placeholder="{{ __('Search for a place, street or landmark') }}"
                    autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" data-map-search-go>
                    {{ __('Search') }}
                </button>
            </div>
            <div class="map-picker-results" data-map-results hidden></div>
        </div>
    @endif

    <div id="{{ $mapId }}" class="map-picker-canvas" style="height: {{ $height }}"></div>

    <div class="map-picker-foot">
        <p class="map-picker-hint">
            {{ $hint ?? ($readonly
                ? __('The saved location.')
                : __('Click the map or drag the pin to set the location.')) }}
        </p>

        @unless ($readonly)
            <button type="button" class="btn btn-sm btn-link map-picker-clear" data-map-clear hidden>
                {{ __('Clear the pin') }}
            </button>
        @endunless
    </div>
</div>

@once
    @push('styles')
        <style>
            /* `map.css` already styles `#map` — a single hard-coded id, which is
               exactly what a component that can appear twice on a page cannot
               use. These are the same rules keyed to the class instead. */
            .map-picker-canvas {
                width: 100%;
                border-radius: 8px;
                border: 1px solid var(--bs-border-color, #dee2e6);
                z-index: 0; /* under select2 and modals, which the vendor theme puts at 1050+ */
            }

            .map-picker-search {
                position: relative;
                margin-bottom: .5rem;
            }

            /* The result list overlays the map rather than pushing it down — a
               map that jumps every time you type is unusable. */
            .map-picker-results {
                position: absolute;
                z-index: 500; /* over the canvas (0), under select2 */
                inset-inline: 0;
                top: calc(100% + 2px);
                max-height: 260px;
                overflow-y: auto;
                background: var(--bs-body-bg, #fff);
                border: 1px solid var(--bs-border-color, #dee2e6);
                border-radius: 8px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
            }

            .map-picker-result {
                display: block;
                width: 100%;
                text-align: start;
                padding: .5rem .75rem;
                border: 0;
                background: none;
                font-size: .875rem;
                line-height: 1.45;
                color: inherit;
                border-bottom: 1px solid var(--bs-border-color-translucent, #e9ecef);
            }
            .map-picker-result:last-child { border-bottom: 0; }
            .map-picker-result:hover,
            .map-picker-result:focus { background: var(--bs-secondary-bg, #f1f3f5); }

            .map-picker-result .place { font-weight: 500; }
            .map-picker-result .where {
                display: block;
                font-size: .8125rem;
                color: var(--bs-secondary-color, #6c757d);
            }

            .map-picker-empty {
                padding: .625rem .75rem;
                font-size: .8125rem;
                color: var(--bs-secondary-color, #6c757d);
            }

            .map-picker-foot {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }

            .map-picker-hint {
                margin: .5rem 0 0;
                font-size: .8125rem;
                color: var(--bs-secondary-color, #6c757d);
            }

            .map-picker-clear { padding: .25rem 0; font-size: .8125rem; }

            /* `L.divIcon` renders a white bordered box by default — fine for a
               label, wrong for a pin drawn as its own shape. */
            .map-picker-pin {
                background: none;
                border: 0;
            }
            .map-picker-pin.is-draggable { cursor: grab; }
            .map-picker-pin.is-draggable:active { cursor: grabbing; }
            .leaflet-dragging .map-picker-pin.is-draggable { cursor: grabbing; }

            /* Leaflet ships its own attribution styling; this only stops it
               fighting the panel's font stack. */
            .map-picker .leaflet-container { font-family: inherit; }
        </style>
    @endpush

    @push('scripts')
        <script>
            /**
             * Wires a Leaflet map to a pair of coordinate inputs.
             *
             * Four things can move the pin and they must not fight:
             *   - the person clicking or dragging on the map,
             *   - the person typing into the lat/lng boxes,
             *   - the city <select> changing,
             *   - a place search result being chosen.
             *
             * The inputs are the single source of truth. Everything else writes
             * to them and then re-reads; that way there is one path into the
             * pin's position rather than four that can drift apart.
             */
            window.initMapPicker = function (options) {
                var root = document.getElementById(options.mapId).closest('[data-map-picker]');
                var canvas = document.getElementById(options.mapId);
                var latInput = document.getElementById(options.latInput);
                var lngInput = document.getElementById(options.lngInput);

                // A form rendered without one of these is a wiring mistake, and a
                // silent dead map is the worst way to find out.
                if (!canvas || !latInput || !lngInput) {
                    if (window.console) {
                        console.warn('[map-picker] missing element', options);
                    }
                    return;
                }

                // --- what the coordinate boxes are allowed to be
                // Done here rather than in each form so one prop covers both,
                // and done after the inputs are found so a mis-wired id shows up
                // as a warning above instead of a silently hidden field.
                function fieldWrapper(input) {
                    return input.closest('.mb-3') || input.closest('.form-group') || input;
                }

                if (options.inputs === 'hidden') {
                    [latInput, lngInput].forEach(function (input) {
                        // `hidden` on the wrapper, not `display:none` inline, so
                        // the vendor CSS cannot out-specify it.
                        fieldWrapper(input).hidden = true;
                        // Still submitted — the map writes real values into them.
                        input.setAttribute('readonly', 'readonly');
                    });
                } else if (options.inputs === 'readonly') {
                    [latInput, lngInput].forEach(function (input) {
                        input.setAttribute('readonly', 'readonly');
                    });
                }

                var typingAllowed = options.inputs === 'visible' && !options.readonly;

                var map = L.map(canvas, { scrollWheelZoom: false })
                    .setView([options.defaultLat, options.defaultLng], options.defaultZoom);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);

                // Scroll-zoom only once the map has been clicked, so a long form
                // does not trap the page scroll as the cursor passes over it.
                map.on('click focus', function () { map.scrollWheelZoom.enable(); });
                map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

                var marker = null;
                var clearButton = root ? root.querySelector('[data-map-clear]') : null;

                function readInputs() {
                    var lat = parseFloat(latInput.value);
                    var lng = parseFloat(lngInput.value);
                    // Reject NaN and out-of-range rather than handing Leaflet a
                    // position it will throw on.
                    if (isNaN(lat) || isNaN(lng)) return null;
                    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
                    return [lat, lng];
                }

                /**
                 * The pin, drawn inline rather than fetched.
                 *
                 * Leaflet's default marker is a PNG that its stylesheet points
                 * at with a relative path — the single most common way a Leaflet
                 * map ends up with an invisible marker, because every link in
                 * that chain (the path, the request, a blocking extension, a
                 * stale cache) fails silently and leaves a correctly-positioned
                 * element with nothing drawn in it. An inline SVG has no request
                 * to fail. It is also bigger and carries the panel's own navy,
                 * so it reads as ours among OpenStreetMap's own POI icons rather
                 * than as one more of them.
                 */
                function pinIcon() {
                    var svg = '<svg width="32" height="44" viewBox="0 0 32 44" fill="none" '
                        + 'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
                        // The shadow on the ground, so the pin reads as standing up.
                        + '<ellipse cx="16" cy="41" rx="6" ry="2.4" fill="rgba(0,0,0,.28)"/>'
                        + '<path d="M16 1.5c-7.18 0-13 5.82-13 13 0 9.2 11.1 23.2 12.2 24.6a1 1 0 0 0 1.6 0'
                        + 'C17.9 37.7 29 23.7 29 14.5c0-7.18-5.82-13-13-13z" '
                        + 'fill="#0E4C75" stroke="#ffffff" stroke-width="2.5"/>'
                        + '<circle cx="16" cy="14.5" r="4.6" fill="#ffffff"/>'
                        + '</svg>';

                    return L.divIcon({
                        html: svg,
                        className: 'map-picker-pin' + (options.readonly ? '' : ' is-draggable'),
                        iconSize: [32, 44],
                        // The tip of the pin is the coordinate, not its middle.
                        iconAnchor: [16, 44],
                    });
                }

                function drawPin(position) {
                    if (!position) {
                        if (marker) { map.removeLayer(marker); marker = null; }
                        if (clearButton) clearButton.hidden = true;
                        return;
                    }
                    if (marker) {
                        marker.setLatLng(position);
                    } else {
                        marker = L.marker(position, {
                            icon: pinIcon(),
                            draggable: !options.readonly,
                            autoPan: true,
                            title: options.readonly ? '' : options.dragTitle,
                        }).addTo(map);

                        if (!options.readonly) {
                            marker.on('dragend', function () {
                                writeInputs(marker.getLatLng());
                            });
                        }
                    }
                    if (clearButton) clearButton.hidden = false;
                }

                function writeInputs(latlng) {
                    // Seven places, matching the decimal(10,7) columns. Rounding
                    // here rather than at save time means the box shows exactly
                    // what will be stored.
                    latInput.value = latlng.lat.toFixed(7);
                    lngInput.value = latlng.lng.toFixed(7);
                    // Dispatched so anything else listening (validation, a dirty
                    // flag) sees a real change, not a silent assignment.
                    latInput.dispatchEvent(new Event('input', { bubbles: true }));
                    lngInput.dispatchEvent(new Event('input', { bubbles: true }));
                    drawPin([latlng.lat, latlng.lng]);
                }

                if (!options.readonly) {
                    map.on('click', function (e) { writeInputs(e.latlng); });

                    if (clearButton) {
                        clearButton.addEventListener('click', function () {
                            latInput.value = '';
                            lngInput.value = '';
                            latInput.dispatchEvent(new Event('input', { bubbles: true }));
                            lngInput.dispatchEvent(new Event('input', { bubbles: true }));
                            drawPin(null);
                        });
                    }
                }

                if (typingAllowed) {
                    ['input', 'change'].forEach(function (evt) {
                        [latInput, lngInput].forEach(function (input) {
                            input.addEventListener(evt, function () {
                                var position = readInputs();
                                drawPin(position);
                                if (position) map.panTo(position);
                            });
                        });
                    });
                }

                // Opening position: an existing pin wins over the city, because a
                // saved building is more specific than the governorate it sits in.
                var initial = readInputs();
                if (initial) {
                    drawPin(initial);
                    map.setView(initial, options.pinZoom);
                }

                // --- the city link
                var citySelect = options.citySelect
                    ? document.getElementById(options.citySelect)
                    : null;

                function centreOnCity(animate) {
                    if (!citySelect) return;
                    var option = citySelect.options[citySelect.selectedIndex];
                    if (!option) return;

                    var lat = parseFloat(option.getAttribute('data-lat'));
                    var lng = parseFloat(option.getAttribute('data-lng'));
                    // A city with no coordinates yet leaves the map where it is
                    // rather than throwing it at [0, 0] in the Atlantic.
                    if (isNaN(lat) || isNaN(lng)) return;

                    map.setView([lat, lng], options.cityZoom, { animate: !!animate });
                }

                if (citySelect) {
                    citySelect.addEventListener('change', function () {
                        centreOnCity(true);
                        // Changing city does not move a pin somebody already
                        // placed — it only changes what you are looking at. The
                        // coordinates stay theirs to edit.
                    });

                    // select2 replaces the control and swallows the native event,
                    // and this panel runs select2 on most selects.
                    if (window.jQuery && jQuery.fn.select2) {
                        jQuery(citySelect).on('select2:select', function () { centreOnCity(true); });
                    }

                    // No pin yet but a city already chosen (an edit form whose row
                    // was saved before coordinates existed): open on the city.
                    if (!initial) centreOnCity(false);
                }

                // --- place search (OpenStreetMap Nominatim)
                var searchInput = root ? root.querySelector('[data-map-search]') : null;
                var searchButton = root ? root.querySelector('[data-map-search-go]') : null;
                var results = root ? root.querySelector('[data-map-results]') : null;

                function closeResults() {
                    if (!results) return;
                    results.hidden = true;
                    results.innerHTML = '';
                }

                function showMessage(text) {
                    if (!results) return;
                    results.innerHTML = '';
                    var p = document.createElement('div');
                    p.className = 'map-picker-empty';
                    p.textContent = text;
                    results.appendChild(p);
                    results.hidden = false;
                }

                function runSearch() {
                    if (!searchInput || !results) return;
                    var query = searchInput.value.trim();
                    if (query.length < 3) {
                        showMessage(options.i18n.tooShort);
                        return;
                    }

                    showMessage(options.i18n.searching);

                    // Bias to what is on screen without excluding anything else:
                    // "Nasr City" typed while looking at Cairo should mean the
                    // one in Cairo, but a different governorate must still be
                    // reachable without panning there first.
                    var b = map.getBounds();
                    var url = 'https://nominatim.openstreetmap.org/search'
                        + '?format=json&limit=6&addressdetails=1'
                        + '&accept-language=' + encodeURIComponent(options.locale)
                        + '&viewbox=' + [b.getWest(), b.getNorth(), b.getEast(), b.getSouth()].join(',')
                        + '&q=' + encodeURIComponent(query);

                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(function (rows) {
                            if (!rows || !rows.length) {
                                showMessage(options.i18n.noResults);
                                return;
                            }
                            results.innerHTML = '';
                            rows.forEach(function (row) {
                                var name = row.display_name || '';
                                var head = name.split(',')[0];
                                var rest = name.slice(head.length + 1).trim();

                                var button = document.createElement('button');
                                button.type = 'button'; // never submits the form it sits in
                                button.className = 'map-picker-result';

                                var strong = document.createElement('span');
                                strong.className = 'place';
                                strong.textContent = head;
                                button.appendChild(strong);

                                if (rest) {
                                    var small = document.createElement('span');
                                    small.className = 'where';
                                    small.textContent = rest;
                                    button.appendChild(small);
                                }

                                button.addEventListener('click', function () {
                                    var lat = parseFloat(row.lat);
                                    var lng = parseFloat(row.lon);
                                    if (isNaN(lat) || isNaN(lng)) return;
                                    // A search result is a chosen location, so it
                                    // sets the value — unlike the city, which only
                                    // changes the view.
                                    writeInputs({ lat: lat, lng: lng });
                                    map.setView([lat, lng], options.pinZoom);
                                    closeResults();
                                });

                                results.appendChild(button);
                            });
                            results.hidden = false;
                        })
                        .catch(function () {
                            // Nominatim rate-limits and can simply be unreachable.
                            // Saying so beats an empty box that looks like "no
                            // such place".
                            showMessage(options.i18n.searchFailed);
                        });
                }

                if (searchInput) {
                    searchInput.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            // Enter in this box means "search", not "save the
                            // laundry" — the surrounding form must not submit.
                            e.preventDefault();
                            runSearch();
                        }
                        if (e.key === 'Escape') closeResults();
                    });
                    if (searchButton) {
                        searchButton.addEventListener('click', runSearch);
                    }
                    document.addEventListener('click', function (e) {
                        if (root && !root.contains(e.target)) closeResults();
                    });
                }

                // Leaflet measures its container on creation. Inside a tab, a
                // collapsed block, or a form that is still laying out, that
                // measurement is zero and the tiles come back grey.
                setTimeout(function () { map.invalidateSize(); }, 200);
                window.addEventListener('resize', function () { map.invalidateSize(); });

                return map;
            };
        </script>
    @endpush
@endonce

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.initMapPicker({
                mapId: @json($mapId),
                latInput: @json($latInput),
                lngInput: @json($lngInput),
                citySelect: @json($citySelect),
                inputs: @json($inputs),
                defaultLat: {{ $defaultLat }},
                defaultLng: {{ $defaultLng }},
                defaultZoom: {{ $defaultZoom }},
                cityZoom: {{ $cityZoom }},
                pinZoom: {{ $pinZoom }},
                readonly: {{ $readonly ? 'true' : 'false' }},
                locale: @json(app()->getLocale()),
                dragTitle: @json(__('Drag to move the location')),
                i18n: {
                    searching: @json(__('Searching…')),
                    noResults: @json(__('No place found by that name.')),
                    tooShort: @json(__('Type at least three letters.')),
                    searchFailed: @json(__('The place search is unavailable right now. Set the pin on the map instead.')),
                },
            });
        });
    </script>
@endpush
