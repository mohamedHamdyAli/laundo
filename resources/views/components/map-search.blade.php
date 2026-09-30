@props([
    // What to tell somebody when Nominatim cannot be reached. It differs by
    // map: a picker's answer is «set the pin by hand», a zone drawer's is «move
    // the map yourself».
    'failed' => null,
])

{{-- A place search over OpenStreetMap's Nominatim, shared by every map in the
     panel (`x-map-picker`, `x-zone-drawer`). The map it searches is handed to
     `attachPlaceSearch()` by the component that owns it, together with what a
     chosen place should do — a picker drops its pin there, a zone drawer only
     looks there.

     Not a <form>: this sits inside the module's own form, and a nested one is
     invalid markup that submits the wrong thing on Enter. --}}
<div class="map-picker-search" data-map-search-box
    data-locale="{{ app()->getLocale() }}"
    data-searching="{{ __('Searching…') }}"
    data-no-results="{{ __('No place found by that name.') }}"
    data-too-short="{{ __('Type at least three letters.') }}"
    data-failed="{{ $failed ?? __('The place search is unavailable right now. Set the pin on the map instead.') }}">
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

@once
    @push('styles')
        <style>
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
        </style>
    @endpush

    @push('scripts')
        <script>
            /**
             * Wires a `x-map-search` box to a Leaflet map.
             *
             * Search runs on Enter or the button, never as you type: Nominatim's
             * usage policy forbids autocomplete against its public server, and
             * allows one request a second.
             *
             * `onPick` receives the chosen place — {lat, lng, name, bounds}, with
             * `bounds` an L.LatLngBounds or null — and decides what it means for
             * this map. The box only finds places.
             */
            window.attachPlaceSearch = function (map, box, onPick) {
                if (!map || !box) return;

                var input = box.querySelector('[data-map-search]');
                var button = box.querySelector('[data-map-search-go]');
                var results = box.querySelector('[data-map-results]');
                if (!input || !results) return;

                function closeResults() {
                    results.hidden = true;
                    results.innerHTML = '';
                }

                function showMessage(text) {
                    results.innerHTML = '';
                    var p = document.createElement('div');
                    p.className = 'map-picker-empty';
                    p.textContent = text;
                    results.appendChild(p);
                    results.hidden = false;
                }

                function boundsOf(row) {
                    // [south, north, west, east], as strings.
                    var b = row.boundingbox;
                    if (!b || b.length !== 4) return null;
                    var s = parseFloat(b[0]), n = parseFloat(b[1]), w = parseFloat(b[2]), e = parseFloat(b[3]);
                    if ([s, n, w, e].some(isNaN)) return null;
                    return L.latLngBounds([[s, w], [n, e]]);
                }

                function runSearch() {
                    var query = input.value.trim();
                    if (query.length < 3) {
                        showMessage(box.dataset.tooShort);
                        return;
                    }

                    showMessage(box.dataset.searching);

                    // Bias to what is on screen without excluding anything else:
                    // "Nasr City" typed while looking at Cairo should mean the
                    // one in Cairo, but a different governorate must still be
                    // reachable without panning there first.
                    var b = map.getBounds();
                    var url = 'https://nominatim.openstreetmap.org/search'
                        + '?format=json&limit=6&addressdetails=1'
                        + '&accept-language=' + encodeURIComponent(box.dataset.locale || '')
                        + '&viewbox=' + [b.getWest(), b.getNorth(), b.getEast(), b.getSouth()].join(',')
                        + '&q=' + encodeURIComponent(query);

                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(function (rows) {
                            if (!rows || !rows.length) {
                                showMessage(box.dataset.noResults);
                                return;
                            }
                            results.innerHTML = '';
                            rows.forEach(function (row) {
                                var name = row.display_name || '';
                                var head = name.split(',')[0];
                                var rest = name.slice(head.length + 1).trim();

                                var choice = document.createElement('button');
                                choice.type = 'button'; // never submits the form it sits in
                                choice.className = 'map-picker-result';

                                var strong = document.createElement('span');
                                strong.className = 'place';
                                strong.textContent = head;
                                choice.appendChild(strong);

                                if (rest) {
                                    var small = document.createElement('span');
                                    small.className = 'where';
                                    small.textContent = rest;
                                    choice.appendChild(small);
                                }

                                choice.addEventListener('click', function () {
                                    var lat = parseFloat(row.lat);
                                    var lng = parseFloat(row.lon);
                                    if (isNaN(lat) || isNaN(lng)) return;
                                    closeResults();
                                    onPick({ lat: lat, lng: lng, name: head, bounds: boundsOf(row) });
                                });

                                results.appendChild(choice);
                            });
                            results.hidden = false;
                        })
                        .catch(function () {
                            // Nominatim rate-limits and can simply be unreachable.
                            // Saying so beats an empty box that looks like "no
                            // such place".
                            showMessage(box.dataset.failed);
                        });
                }

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        // Enter in this box means "search", not "save the form
                        // around it" — the surrounding form must not submit.
                        e.preventDefault();
                        runSearch();
                    }
                    if (e.key === 'Escape') closeResults();
                });
                if (button) button.addEventListener('click', runSearch);
                document.addEventListener('click', function (e) {
                    if (!box.contains(e.target)) closeResults();
                });
            };
        </script>
    @endpush
@endonce
