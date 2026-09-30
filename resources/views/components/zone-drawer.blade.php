@props([
    // The hidden input the drawing is written to, as JSON — [[lat, lng], …].
    'input',

    // The other zones, drawn faintly so a new one can be drawn up to their
    // edges without crossing them: [{name, points}].
    'others' => [],

    // A city <select> whose options carry data-lat / data-lng: choosing the
    // city brings the map there, as on the laundry form.
    'citySelect' => null,

    // A place search above the map (`x-map-search`). On a read-only map too:
    // «which zone is this street in» is a question the overview answers.
    'search' => true,

    'readonly' => false,
    'height' => '460px',
])

@php
    $mapId = 'zone-drawer-'.Str::random(8);

    // The ways to draw. «Corners» is the click-by-click drawing; every other
    // tool is a drag that turns into corners, so what is saved — and what the
    // server checks — is the same ring of points whichever was used.
    $tools = [
        'points' => [
            __('Corners'),
            null,
            '<path d="M3 12 8 3.5 13 11" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="3" cy="12" r="1.8" fill="currentColor"/><circle cx="8" cy="3.5" r="1.8" fill="currentColor"/><circle cx="13" cy="11" r="1.8" fill="currentColor"/>',
        ],
        'rectangle' => [
            __('Rectangle'),
            __('Drag on the map from one corner of the zone to the opposite one.'),
            '<rect x="2.5" y="3.5" width="11" height="9" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/>',
        ],
        'circle' => [
            __('Circle'),
            __('Press where the centre is and drag out to the edge.'),
            '<circle cx="8" cy="8" r="5.5" fill="none" stroke="currentColor" stroke-width="1.5"/>',
        ],
        'triangle' => [
            __('Triangle'),
            __('Drag on the map and a triangle is drawn inside what you drag.'),
            '<path d="M8 2.5 14 13H2Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        ],
        'freehand' => [
            __('Freehand'),
            __('Hold the mouse down and trace the border; let go to finish.'),
            '<path d="M2 11c2-5 4 1 6-3s4-4 6 1" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        ],
    ];
@endphp

<div class="zone-drawer" data-zone-drawer>
    @if ($search)
        <x-map-search :failed="__('The place search is unavailable right now. Move the map to the place yourself.')" />
    @endif

    @unless ($readonly)
        <div class="zone-drawer-tools" role="group" aria-label="{{ __('Draw with') }}" data-zone-tools>
            <span class="zone-drawer-tools-label">{{ __('Draw with') }}</span>
            @foreach ($tools as $mode => [$label, $hint, $icon])
                <button type="button" class="btn btn-sm btn-outline-secondary{{ $mode === 'points' ? ' active' : '' }}"
                    data-zone-mode="{{ $mode }}" data-hint="{{ $hint }}"
                    aria-pressed="{{ $mode === 'points' ? 'true' : 'false' }}">
                    {{-- The icons are this file's own constants, not input. --}}
                    <svg viewBox="0 0 16 16" aria-hidden="true">{!! $icon !!}</svg>
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
        <p class="zone-drawer-mode-hint" data-zone-mode-hint
            data-replaces="{{ __('A shape replaces the drawing — Undo brings it back — and its corners can then be moved like any other.') }}"
            hidden></p>
    @endunless

    <div id="{{ $mapId }}" class="zone-drawer-canvas" style="height: {{ $height }}"></div>

    <div class="zone-drawer-foot">
        <p class="zone-drawer-hint mb-0">
            @if ($readonly)
                {{ __('The zone as it was drawn.') }}
            @else
                {{ __('Click the map to add a corner, drag a corner to move it, double-click a corner to remove it. Other zones are shown in grey — a corner put near one snaps onto its corner or edge, so the two share the border.') }}
            @endif
            {{-- Not `d-block`: Bootstrap's is `display: block !important`,
                 which beats the `hidden` attribute and showed this warning on
                 an empty map. --}}
            <span class="zone-drawer-limit text-danger" data-zone-limit hidden>
                {{ __('A zone can have at most :count corners. Draw it with fewer.', ['count' => \App\Support\Geo\Polygon::MAX_POINTS]) }}
            </span>
        </p>

        @unless ($readonly)
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-zone-undo>
                    <i class="fa fa-undo me-1"></i>{{ __('Undo') }}
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-zone-clear>
                    <i class="fa fa-trash me-1"></i>{{ __('Clear the drawing') }}
                </button>
            </div>
        @endunless
    </div>
</div>

@once
    @push('styles')
        <style>
            .zone-drawer-canvas {
                width: 100%;
                border-radius: 8px;
                border: 1px solid var(--bs-border-color, #dee2e6);
                z-index: 0; /* under select2 and modals */
            }
            .zone-drawer-foot {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                margin-top: .5rem;
            }
            .zone-drawer-hint {
                font-size: .8125rem;
                color: var(--bs-secondary-color, #6c757d);
            }
            /* The corner handles: drawn with CSS, nothing fetched. */
            .zone-drawer-corner {
                width: 14px;
                height: 14px;
                margin: -7px 0 0 -7px;
                border-radius: 50%;
                background: #fff;
                border: 3px solid #0E4C75;
                cursor: grab;
            }
            .zone-drawer .leaflet-container { font-family: inherit; }
            .zone-drawer-tools {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: .375rem;
                margin-bottom: .5rem;
            }
            .zone-drawer-tools-label {
                font-size: .8125rem;
                color: var(--bs-secondary-color, #6c757d);
                margin-inline-end: .25rem;
            }
            .zone-drawer-tools .btn {
                display: inline-flex;
                align-items: center;
                gap: .375rem;
            }
            .zone-drawer-tools .btn svg { width: 16px; height: 16px; flex-shrink: 0; }
            .zone-drawer-mode-hint {
                font-size: .8125rem;
                color: #0E4C75;
                margin: 0 0 .5rem;
            }
            .zone-drawer-mode-hint[hidden] { display: none; }
            /* While a shape tool is on, the map is a drawing surface: no grab
               hand, no scrolling the page from it on a touch screen. */
            .zone-drawer-canvas.is-shaping,
            .zone-drawer-canvas.is-shaping .leaflet-interactive { cursor: crosshair; }
            .zone-drawer-canvas.is-shaping { touch-action: none; }
            /* A tool picked means «draw a shape»: a drag that happens to start
               on an old corner draws it rather than moving that corner. The
               corners are live again once the tool goes back to «Corners». */
            .zone-drawer-canvas.is-shaping .zone-drawer-corner { pointer-events: none; }
            .zone-drawer-limit { display: block; }
            .zone-drawer-limit[hidden] { display: none; }
            .zone-drawer-found-label {
                font-weight: 500;
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            /**
             * Draws a zone as a ring of corners on a Leaflet map, writing it to
             * a hidden input as JSON.
             *
             * Our own small tool on the Leaflet already loaded on every panel
             * page, rather than a drawing plugin: the brief is no new vendor
             * bundles, and a zone needs exactly three gestures — add a corner,
             * move one, remove one.
             *
             * A click adds a corner **on the nearest edge**, not at the end of
             * the ring: on a drawing that already exists, «after the last
             * corner» is wherever the owner happened to start, and appending
             * there folds the shape over itself. The input is the single
             * source of truth; every gesture writes it and redraws from it.
             */
            window.initZoneDrawer = function (options) {
                var canvas = document.getElementById(options.mapId);
                var input = document.getElementById(options.input);

                if (!canvas || !input) {
                    if (window.console) console.warn('[zone-drawer] missing element', options);
                    return;
                }

                var root = canvas.closest('[data-zone-drawer]');
                var limit = root ? root.querySelector('[data-zone-limit]') : null;
                var map = L.map(canvas, { scrollWheelZoom: false, doubleClickZoom: false })
                    .setView([30.0444, 31.2357], 11); // Cairo until something says otherwise

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);

                map.on('click focus', function () { map.scrollWheelZoom.enable(); });
                map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

                // --- the neighbours, faint and not clickable
                var bounds = L.latLngBounds([]);
                var neighbours = [];

                (options.others || []).forEach(function (zone) {
                    if (!zone.points || zone.points.length < 3) return;
                    neighbours.push(zone.points);
                    var layer = L.polygon(zone.points, {
                        color: '#6c757d', weight: 1, dashArray: '4 4',
                        fillColor: '#6c757d', fillOpacity: .12, interactive: true,
                    }).addTo(map);
                    // textContent-safe: a tooltip given a string is set as text
                    // only when it is a DOM node, so build one.
                    var label = document.createElement('span');
                    label.textContent = zone.name || '';
                    layer.bindTooltip(label, { sticky: true });
                    bounds.extend(layer.getBounds());
                });

                // --- the drawing
                var points = [];
                var history = [];
                var shape = null;
                var corners = [];

                try {
                    var saved = JSON.parse(input.value || '[]');
                    if (Array.isArray(saved)) {
                        points = saved.filter(function (p) {
                            return Array.isArray(p) && p.length === 2 && !isNaN(parseFloat(p[0])) && !isNaN(parseFloat(p[1]));
                        }).map(function (p) { return [parseFloat(p[0]), parseFloat(p[1])]; });
                    }
                } catch (e) {
                    points = [];
                }

                function write() {
                    input.value = points.length ? JSON.stringify(points.map(function (p) {
                        return [Number(p[0].toFixed(7)), Number(p[1].toFixed(7))];
                    })) : '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }

                function remember() {
                    history.push(points.map(function (p) { return [p[0], p[1]]; }));
                    if (history.length > 50) history.shift();
                }

                /**
                 * A corner put near a neighbour goes exactly onto it — onto its
                 * corner if one is close, else onto the nearest point of its
                 * edge. Without it, two zones drawn along the same street
                 * either overlap by a few metres (and the save is refused) or
                 * leave a strip between them that belongs to nobody.
                 * Measured in pixels, so it feels the same at every zoom.
                 */
                var SNAP_PIXELS = 12;

                // Screen distance, unrounded — `latLngToLayerPoint` rounds to
                // whole pixels, which is metres at a city's zoom.
                function pixels(a, b) {
                    var zoom = map.getZoom();
                    return map.project(a, zoom).distanceTo(map.project(b, zoom));
                }

                /**
                 * The nearest point of an edge, worked out on plain [lat, lng]
                 * — the same plane the server checks overlaps on. Done on the
                 * screen's projection instead, a point «on» a long edge is off
                 * it by tens of centimetres, which the server reads as inside
                 * the neighbour.
                 */
                function nearestOnEdge(p, a, b) {
                    var dLng = b[1] - a[1];
                    var dLat = b[0] - a[0];
                    var lengthSquared = dLng * dLng + dLat * dLat;
                    if (!lengthSquared) return [a[0], a[1]];
                    var t = ((p[1] - a[1]) * dLng + (p[0] - a[0]) * dLat) / lengthSquared;
                    t = Math.max(0, Math.min(1, t));
                    return [a[0] + t * dLat, a[1] + t * dLng];
                }

                function snap(latlng) {
                    var best = null;
                    var bestDistance = SNAP_PIXELS;

                    neighbours.forEach(function (ring) {
                        ring.forEach(function (corner) {
                            var d = pixels(latlng, L.latLng(corner[0], corner[1]));
                            if (d < bestDistance) { bestDistance = d; best = L.latLng(corner[0], corner[1]); }
                        });
                    });

                    if (best) return best;

                    bestDistance = SNAP_PIXELS;
                    neighbours.forEach(function (ring) {
                        for (var i = 0; i < ring.length; i++) {
                            var onEdge = nearestOnEdge([latlng.lat, latlng.lng], ring[i], ring[(i + 1) % ring.length]);
                            var candidate = L.latLng(onEdge[0], onEdge[1]);
                            var d = pixels(latlng, candidate);
                            if (d < bestDistance) { bestDistance = d; best = candidate; }
                        }
                    });

                    return best || latlng;
                }

                function cornerIcon() {
                    return L.divIcon({ className: 'zone-drawer-corner', iconSize: null });
                }

                function redraw() {
                    if (limit) limit.hidden = points.length < options.maxCorners;

                    if (shape) { map.removeLayer(shape); shape = null; }
                    corners.forEach(function (c) { map.removeLayer(c); });
                    corners = [];

                    if (points.length >= 2) {
                        shape = (points.length >= 3 ? L.polygon : L.polyline)(points, {
                            color: '#0E4C75', weight: 3, fillColor: '#0E4C75', fillOpacity: .18,
                            interactive: false,
                        }).addTo(map);
                    }

                    if (options.readonly) return;

                    points.forEach(function (p, index) {
                        var corner = L.marker(p, { icon: cornerIcon(), draggable: true, keyboard: false }).addTo(map);

                        corner.on('dragstart', remember);
                        corner.on('drag', function (e) {
                            points[index] = [e.target.getLatLng().lat, e.target.getLatLng().lng];
                            if (shape) shape.setLatLngs(points);
                        });
                        corner.on('dragend', function (e) {
                            var snapped = snap(e.target.getLatLng());
                            points[index] = [snapped.lat, snapped.lng];
                            write();
                            redraw();
                        });
                        corner.on('dblclick', function (e) {
                            L.DomEvent.stop(e);
                            remember();
                            points.splice(index, 1);
                            write();
                            redraw();
                        });

                        corners.push(corner);
                    });
                }

                /**
                 * Where a new corner goes: after the start of the edge nearest
                 * the click, measured on the screen so it matches what the owner
                 * sees at any zoom.
                 */
                function insertionIndex(latlng) {
                    if (points.length < 3) return points.length;

                    var click = map.latLngToLayerPoint(latlng);
                    var best = points.length;
                    var bestDistance = Infinity;

                    for (var i = 0; i < points.length; i++) {
                        var a = map.latLngToLayerPoint(points[i]);
                        var b = map.latLngToLayerPoint(points[(i + 1) % points.length]);
                        var distance = L.LineUtil.pointToSegmentDistance(click, a, b);
                        if (distance < bestDistance) {
                            bestDistance = distance;
                            best = i + 1;
                        }
                    }

                    return best;
                }

                // Which tool is on: 'points' (click by click) or one of the
                // shapes. `shapedAt` is when a shape was last finished — the
                // browser follows the release of a drag with a click, and that
                // click must not add a corner to the shape just drawn.
                var mode = 'points';
                var shapedAt = 0;

                if (!options.readonly) {
                    map.on('click', function (e) {
                        if (mode !== 'points' || Date.now() - shapedAt < 400) return;

                        // A click on a corner reaches the map too. Taken as «add
                        // a corner» it redrew every handle, so the second click
                        // of a double-click landed on a new element and the
                        // corner was never removed — two were added instead.
                        var target = e.originalEvent && e.originalEvent.target;
                        if (target && target.closest && target.closest('.zone-drawer-corner')) return;

                        // The server's limit, said here before it has to be said
                        // after a save.
                        if (points.length >= options.maxCorners) {
                            if (limit) limit.hidden = false;
                            return;
                        }

                        var snapped = snap(e.latlng);
                        remember();
                        points.splice(insertionIndex(snapped), 0, [snapped.lat, snapped.lng]);
                        write();
                        redraw();
                    });

                    var undo = root.querySelector('[data-zone-undo]');
                    var clear = root.querySelector('[data-zone-clear]');

                    if (undo) undo.addEventListener('click', function () {
                        if (!history.length) return;
                        points = history.pop();
                        write();
                        redraw();
                    });

                    if (clear) clear.addEventListener('click', function () {
                        if (!points.length) return;
                        remember();
                        points = [];
                        write();
                        redraw();
                    });

                    // --- shape tools: a drag that becomes corners
                    var tools = root.querySelector('[data-zone-tools]');
                    var modeHint = root.querySelector('[data-zone-mode-hint]');
                    var CIRCLE_CORNERS = 32;
                    var FREEHAND_MAX = 100;
                    // The radius `map.distance()` measured the drag with, so the circle is
                    // exactly as big as it was dragged.
                    var EARTH_RADIUS = L.CRS.Earth.R;

                    function setMode(next) {
                        mode = next;
                        var hint = null;
                        if (tools) {
                            tools.querySelectorAll('[data-zone-mode]').forEach(function (button) {
                                var on = button.getAttribute('data-zone-mode') === next;
                                button.classList.toggle('active', on);
                                button.setAttribute('aria-pressed', on ? 'true' : 'false');
                                if (on) hint = button.getAttribute('data-hint');
                            });
                        }
                        if (modeHint) {
                            modeHint.textContent = hint ? hint + ' ' + (modeHint.getAttribute('data-replaces') || '') : '';
                            modeHint.hidden = !hint;
                        }
                        canvas.classList.toggle('is-shaping', next !== 'points');
                        // The map cannot pan under a drag that is drawing.
                        if (next === 'points') map.dragging.enable(); else map.dragging.disable();
                    }

                    if (tools) {
                        tools.addEventListener('click', function (e) {
                            var button = e.target.closest('[data-zone-mode]');
                            if (button) setMode(button.getAttribute('data-zone-mode'));
                        });
                    }

                    /** A rectangle, triangle or circle from where the drag began to where it is. */
                    function shapeFor(kind, a, b) {
                        var north = Math.max(a.lat, b.lat), south = Math.min(a.lat, b.lat);
                        var west = Math.min(a.lng, b.lng), east = Math.max(a.lng, b.lng);

                        if (kind === 'rectangle') {
                            return [[north, west], [north, east], [south, east], [south, west]];
                        }
                        if (kind === 'triangle') {
                            return [[north, (west + east) / 2], [south, east], [south, west]];
                        }
                        // A circle: `a` is the centre, the distance to `b` its
                        // radius in metres, walked round on the sphere so it is
                        // round on the ground and not only on the screen.
                        var radius = map.distance(a, b);
                        var ring = [];
                        var dLat = (radius / EARTH_RADIUS) * (180 / Math.PI);
                        var dLng = dLat / Math.cos(a.lat * Math.PI / 180);
                        for (var i = 0; i < CIRCLE_CORNERS; i++) {
                            var angle = 2 * Math.PI * i / CIRCLE_CORNERS;
                            ring.push([a.lat + dLat * Math.cos(angle), a.lng + dLng * Math.sin(angle)]);
                        }
                        return ring;
                    }

                    /**
                     * A traced line as a handful of corners — a mouse gives a
                     * point every few pixels, and a zone of four hundred handles
                     * cannot be edited. Simplified on the unrounded projection,
                     * keeping the traced positions themselves.
                     */
                    function simplified(latlngs) {
                        var zoom = map.getZoom();
                        var projected = latlngs.map(function (ll) {
                            var p = map.project(ll, zoom);
                            p.latlng = ll;
                            return p;
                        });
                        var tolerance = 3;
                        var kept = L.LineUtil.simplify(projected, tolerance);
                        while (kept.length > FREEHAND_MAX && tolerance < 1000) {
                            tolerance *= 1.6;
                            kept = L.LineUtil.simplify(projected, tolerance);
                        }
                        // Let go near where it began: that is the ring closing,
                        // not one more corner on top of the first.
                        if (kept.length > 3 && kept[0].distanceTo(kept[kept.length - 1]) < SNAP_PIXELS) kept.pop();
                        return kept.map(function (p) { return [p.latlng.lat, p.latlng.lng]; });
                    }

                    /** Onto a neighbour's border, as a click is, and no corner twice in a row. */
                    function settle(ring) {
                        var out = [];
                        ring.forEach(function (p) {
                            var s = snap(L.latLng(p[0], p[1]));
                            var last = out[out.length - 1];
                            if (!last || last[0] !== s.lat || last[1] !== s.lng) out.push([s.lat, s.lng]);
                        });
                        if (out.length > 1 && out[0][0] === out[out.length - 1][0] && out[0][1] === out[out.length - 1][1]) out.pop();
                        return out;
                    }

                    var dragFrom = null;
                    var traced = [];
                    var preview = null;

                    function draftOf(here) {
                        return mode === 'freehand'
                            ? traced.map(function (ll) { return [ll.lat, ll.lng]; })
                            : shapeFor(mode, dragFrom, here);
                    }

                    function stopDrag() {
                        if (preview) { map.removeLayer(preview); preview = null; }
                        dragFrom = null;
                        traced = [];
                    }

                    canvas.addEventListener('pointerdown', function (e) {
                        if (mode === 'points' || e.button !== 0) return;
                        // The zoom buttons and the attribution are not the map.
                        // (Corners take no pointer while a tool is on — see the
                        // styles — so a drag that starts on one draws the shape.)
                        if (e.target.closest && e.target.closest('.leaflet-control')) return;
                        e.preventDefault();
                        if (canvas.setPointerCapture) canvas.setPointerCapture(e.pointerId);
                        dragFrom = map.mouseEventToLatLng(e);
                        traced = [dragFrom];
                    });

                    canvas.addEventListener('pointermove', function (e) {
                        if (!dragFrom) return;
                        var here = map.mouseEventToLatLng(e);
                        if (mode === 'freehand') traced.push(here);
                        var draft = draftOf(here);
                        if (preview) {
                            preview.setLatLngs(draft);
                        } else {
                            preview = (mode === 'freehand' ? L.polyline : L.polygon)(draft, {
                                color: '#0E4C75', weight: 2, dashArray: '6 4',
                                fillColor: '#0E4C75', fillOpacity: .1, interactive: false,
                            }).addTo(map);
                        }
                    });

                    canvas.addEventListener('pointerup', function (e) {
                        if (!dragFrom) return;
                        var here = map.mouseEventToLatLng(e);
                        var travelled = map.latLngToContainerPoint(dragFrom).distanceTo(map.latLngToContainerPoint(here));
                        var ring = null;

                        if (mode === 'freehand') {
                            traced.push(here);
                            ring = simplified(traced);
                        } else if (travelled >= 8) {
                            ring = shapeFor(mode, dragFrom, here);
                        }

                        stopDrag();
                        shapedAt = Date.now();

                        // A tap is not a shape: nothing is replaced, and the tool
                        // stays on for another go.
                        ring = ring ? settle(ring) : null;
                        if (!ring || ring.length < 3) return;

                        remember();
                        points = ring;
                        write();
                        redraw();
                        // Back to corners, so the shape can be adjusted at once.
                        setMode('points');
                    });

                    canvas.addEventListener('pointercancel', stopDrag);
                }

                redraw();

                // Opening view: the drawing itself, else the neighbours, else
                // the chosen city.
                if (points.length >= 3) {
                    map.fitBounds(L.polygon(points).getBounds(), { padding: [30, 30] });
                } else if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [30, 30] });
                }

                var citySelect = options.citySelect ? document.getElementById(options.citySelect) : null;

                function centreOnCity() {
                    if (!citySelect) return;
                    var option = citySelect.options[citySelect.selectedIndex];
                    if (!option) return;
                    var lat = parseFloat(option.getAttribute('data-lat'));
                    var lng = parseFloat(option.getAttribute('data-lng'));
                    if (isNaN(lat) || isNaN(lng)) return;
                    map.setView([lat, lng], 12);
                }

                if (citySelect) {
                    citySelect.addEventListener('change', function () { if (points.length < 3) centreOnCity(); });
                    if (window.jQuery && jQuery.fn.select2) {
                        jQuery(citySelect).on('select2:select', function () { if (points.length < 3) centreOnCity(); });
                    }
                    if (points.length < 3 && !bounds.isValid()) centreOnCity();
                }

                // --- place search: where to look, never a corner
                // A found place moves the view and is marked with a dot that
                // takes no clicks, so a click on it still reaches the map and
                // puts a corner exactly there if that is what is wanted.
                var found = null;
                var searchBox = root ? root.querySelector('[data-map-search-box]') : null;
                if (searchBox && window.attachPlaceSearch) {
                    window.attachPlaceSearch(map, searchBox, function (place) {
                        // A district comes back with its extent; a building with
                        // a box too small to be worth fitting.
                        if (place.bounds && place.bounds.isValid()) {
                            map.fitBounds(place.bounds, { padding: [30, 30], maxZoom: 16 });
                        } else {
                            map.setView([place.lat, place.lng], 16);
                        }

                        if (found) map.removeLayer(found);
                        found = L.circleMarker([place.lat, place.lng], {
                            radius: 7, weight: 3, color: '#dc3545',
                            fillColor: '#ffffff', fillOpacity: 1, interactive: false,
                        }).addTo(map);

                        var label = document.createElement('span');
                        label.className = 'zone-drawer-found-label';
                        label.textContent = place.name || '';
                        found.bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -8] });
                    });
                }

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
            window.initZoneDrawer({
                mapId: @json($mapId),
                input: @json($input),
                citySelect: @json($citySelect),
                others: @json($others),
                readonly: {{ $readonly ? 'true' : 'false' }},
                maxCorners: {{ \App\Support\Geo\Polygon::MAX_POINTS }},
            });
        });
    </script>
@endpush
