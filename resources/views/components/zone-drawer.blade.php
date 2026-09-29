@props([
    // The hidden input the drawing is written to, as JSON — [[lat, lng], …].
    'input',

    // The other zones, drawn faintly so a new one can be drawn up to their
    // edges without crossing them: [{name, points}].
    'others' => [],

    // A city <select> whose options carry data-lat / data-lng: choosing the
    // city brings the map there, as on the laundry form.
    'citySelect' => null,

    'readonly' => false,
    'height' => '460px',
])

@php
    $mapId = 'zone-drawer-'.Str::random(8);
@endphp

<div class="zone-drawer" data-zone-drawer>
    <div id="{{ $mapId }}" class="zone-drawer-canvas" style="height: {{ $height }}"></div>

    <div class="zone-drawer-foot">
        <p class="zone-drawer-hint mb-0">
            @if ($readonly)
                {{ __('The zone as it was drawn.') }}
            @else
                {{ __('Click the map to add a corner, drag a corner to move it, double-click a corner to remove it. Other zones are shown in grey — a corner put near one snaps onto its corner or edge, so the two share the border.') }}
            @endif
            <span class="d-block text-danger" data-zone-limit hidden>
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

                if (!options.readonly) {
                    map.on('click', function (e) {
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
