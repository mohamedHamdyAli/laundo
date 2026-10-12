/*
 * «خريطة السواقين» — draws every driver and keeps them moving.
 *
 * The page hands over its configuration once (#driver-map-config): the zones,
 * the laundries, what this person may do, and every string it prints. Then it
 * asks the data endpoint every `poll` seconds and redraws in place — markers
 * are moved, not rebuilt, so an open card stays open while its driver moves.
 *
 * Everything that came from a person (names, streets, order codes) goes
 * through esc() before it meets markup. Translated strings are the panel's
 * own and are escaped as well, because a translation is editable from the
 * languages screen.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-driver-map]');
    var configNode = document.getElementById('driver-map-config');

    if (!root || !configNode || typeof L === 'undefined') {
        return;
    }

    var config = JSON.parse(configNode.textContent);
    var S = config.strings || {};
    var can = config.abilities || {};

    var els = {
        canvas: root.querySelector('[data-dm-canvas]'),
        list: root.querySelector('[data-dm-list]'),
        empty: root.querySelector('[data-dm-empty]'),
        search: root.querySelector('[data-dm-search]'),
        zone: root.querySelector('[data-dm-zone]'),
        lost: root.querySelector('[data-dm-lost]'),
        updated: document.querySelector('[data-dm-updated]'),
    };

    var state = {
        drivers: [],
        waiting: [],
        counts: {},
        filter: 'all',
        term: '',
        zone: '',
        selected: null,
        expanded: null,
        firstFit: true,
        lastOk: null,
        timer: null,
        failures: 0,
        stopped: false,
    };

    // ------------------------------------------------------------- helpers

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** A translated string with its :placeholders filled — escaped. */
    function t(key, params) {
        var text = S[key] || key;
        Object.keys(params || {}).forEach(function (name) {
            text = text.split(':' + name).join(String(params[name]));
        });
        return esc(text);
    }

    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
        var first = parts[0] ? Array.from(parts[0])[0] : '?';
        var second = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] : '';
        return (first + second).toUpperCase();
    }

    function statusTone(status) {
        return { on_job: 'tone-live', available: 'tone-ok', unavailable: 'tone-neutral', inactive: 'tone-bad' }[status] || 'tone-neutral';
    }

    function pill(tone, text) {
        return '<span class="status-pill ' + tone + '">' + text + '</span>';
    }

    function signalPill(driver) {
        if (driver.signal === 'live') {
            return pill('tone-ok', t('live'));
        }
        if (driver.signal === 'stale' && driver.location) {
            return pill(driver.signal_lost ? 'tone-bad' : 'tone-neutral', t('stale', { time: driver.location.seen }));
        }
        return pill(driver.signal_lost ? 'tone-bad' : 'tone-neutral', t('none'));
    }

    // ----------------------------------------------------------------- map

    var map = L.map(els.canvas, { zoomControl: true }).setView([30.0444, 31.2357], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    var layers = {
        zones: L.layerGroup().addTo(map),
        laundries: L.layerGroup().addTo(map),
        waiting: L.layerGroup(),
        drivers: L.layerGroup().addTo(map),
        route: L.layerGroup().addTo(map),
    };

    var overlays = {};
    overlays[t('zones')] = layers.zones;
    overlays[t('laundries')] = layers.laundries;

    if (can.waiting) {
        layers.waiting.addTo(map);
        overlays[t('waiting_layer')] = layers.waiting;
    }

    L.control.layers(null, overlays, { collapsed: true }).addTo(map);

    var zonePolygons = {};

    (config.zones || []).forEach(function (zone) {
        if (!zone.points || zone.points.length < 3) return;

        var polygon = L.polygon(zone.points, {
            color: zone.serving ? '#0f766e' : '#6c757d',
            weight: 1.5,
            dashArray: zone.serving ? null : '4 4',
            fillColor: zone.serving ? '#0f766e' : '#6c757d',
            fillOpacity: .06,
        }).addTo(layers.zones);

        var label = document.createElement('span');
        label.textContent = zone.name + (zone.serving ? '' : ' — ' + (S.zone_off || ''));
        polygon.bindTooltip(label, { sticky: true });
        zonePolygons[String(zone.id)] = polygon;
    });

    (config.laundries || []).forEach(function (laundry) {
        var marker = L.marker([laundry.lat, laundry.lng], {
            icon: L.divIcon({
                className: 'dm-laundry-pin',
                html: '<span class="dm-laundry-pin-shape"><i class="bi bi-shop"></i></span>',
                iconSize: [22, 22],
                iconAnchor: [11, 11],
            }),
            zIndexOffset: -200,
        }).addTo(layers.laundries);

        var label = document.createElement('span');
        label.textContent = laundry.name + (laundry.address ? ' — ' + laundry.address : '');
        marker.bindTooltip(label);
    });

    var driverMarkers = {};
    var waitingMarkers = {};

    function driverIcon(driver) {
        var classes = ['dm-pin', 'dm-pin--' + driver.status];
        if (driver.is_late) classes.push('dm-pin--late');
        if (driver.signal !== 'live') classes.push('dm-pin--stale');
        if (state.selected === driver.id) classes.push('dm-pin--selected');

        return L.divIcon({
            className: classes.join(' '),
            html: '<span class="dm-pin-disc">' + esc(initials(driver.name)) + '</span>',
            iconSize: [34, 34],
            iconAnchor: [17, 17],
            popupAnchor: [0, -16],
        });
    }

    // ---------------------------------------------------------------- cards

    /**
     * One order the driver is carrying, with its legs under it — a driver
     * holding the pickup and the run to the laundry of the same order is one
     * job, not two cards.
     */
    function orderHtml(legs) {
        var first = legs[0];
        var late = legs.some(function (leg) { return leg.is_late; });
        var lines = [];

        if (first.customer) lines.push('<span class="dm-leg-line">' + t('customer') + ': ' + esc(first.customer) + '</span>');
        if (first.laundry) lines.push('<span class="dm-leg-line">' + t('laundry') + ': ' + esc(first.laundry) + '</span>');

        var code = first.order_url
            ? '<a href="' + esc(first.order_url) + '" target="_blank" rel="noopener">#' + esc(first.order_code) + '</a>'
            : '#' + esc(first.order_code);

        var steps = legs.map(function (leg) {
            var bits = [];
            if (leg.destination) bits.push(t('destination') + ': ' + esc(leg.destination));
            if (leg.due) bits.push(t('due', { time: leg.due }));

            return '<li><span class="dm-step-head"><strong>' + esc(leg.type_label) + '</strong>'
                + (leg.is_late ? pill('tone-bad', t('late')) : pill(leg.status === 'started' ? 'tone-live' : 'tone-neutral', esc(leg.status_label)))
                + '</span>'
                + (bits.length ? '<span class="dm-leg-line">' + bits.join(' · ') + '</span>' : '')
                + '</li>';
        }).join('');

        return '<div class="dm-leg' + (late ? ' dm-leg--late' : '') + '">'
            + '<div class="dm-leg-title"><span>' + code + '</span></div>'
            + lines.join('')
            + '<ul class="dm-steps">' + steps + '</ul></div>';
    }

    /** The legs, grouped by order, in the order the server sent them. */
    function byOrder(legs) {
        var groups = [];
        var index = {};

        legs.forEach(function (leg) {
            if (index[leg.order_id] === undefined) {
                index[leg.order_id] = groups.length;
                groups.push([]);
            }
            groups[index[leg.order_id]].push(leg);
        });

        return groups;
    }

    function driverCard(driver) {
        var facts = [];

        if (driver.phone) facts.push('<dt>' + t('phone') + '</dt><dd><a href="tel:' + esc(driver.phone) + '" dir="ltr">' + esc(driver.phone) + '</a></dd>');
        if (driver.vehicle || driver.plate) facts.push('<dt>' + t('plate') + '</dt><dd>' + esc([driver.vehicle, driver.plate].filter(Boolean).join(' · ')) + '</dd>');
        if (driver.zones && driver.zones.length) facts.push('<dt>' + t('areas') + '</dt><dd>' + esc(driver.zones.join(config.rtl ? '، ' : ', ')) + '</dd>');

        facts.push('<dt>' + t('in_hand') + '</dt><dd>' + (driver.cap
            ? t('of_cap', { held: driver.orders_in_hand, cap: driver.cap })
            : esc(driver.orders_in_hand)) + '</dd>');

        var pills = pill(statusTone(driver.status), t(driver.status)) + signalPill(driver);
        if (driver.is_late) pills += pill('tone-bad', t('late'));

        var legs = driver.legs.length
            ? byOrder(driver.legs).map(orderHtml).join('')
            : '<p class="dm-note">' + t('no_job') + '</p>';

        return '<div class="dm-card">'
            + '<div class="dm-card-head">'
            + '<img class="dm-card-photo" src="' + esc(driver.photo) + '" alt="" loading="lazy">'
            + '<div><span class="dm-card-name">' + esc(driver.name) + '</span>'
            + '<div class="dm-card-pills">' + pills + '</div></div></div>'
            + '<dl class="dm-card-facts">' + facts.join('') + '</dl>'
            + legs
            + '<div class="dm-card-actions">'
            + '<a class="btn btn-sm btn-outline-primary" href="' + esc(driver.url) + '" target="_blank" rel="noopener">' + t('open_driver') + '</a>'
            + '</div></div>';
    }

    /** `result` is what «who can take it» answered, once it has. */
    function waitingCard(item, result, busy) {
        var code = item.order_url
            ? '<a href="' + esc(item.order_url) + '" target="_blank" rel="noopener">#' + esc(item.order_code) + '</a>'
            : '#' + esc(item.order_code);
        var lines = [];

        if (item.customer) lines.push('<span class="dm-leg-line">' + t('customer') + ': ' + esc(item.customer) + '</span>');
        if (item.place) lines.push('<span class="dm-leg-line">' + esc(item.place) + '</span>');
        if (item.due) lines.push('<span class="dm-leg-line">' + t('due', { time: item.due }) + '</span>');
        if (item.legs_waiting > 1) lines.push('<span class="dm-leg-line">' + t('legs_waiting', { count: item.legs_waiting }) + '</span>');

        return '<div class="dm-card" data-dm-waiting="' + esc(item.task_id) + '">'
            + '<div class="dm-leg-title"><span>' + code + ' · ' + esc(item.type_label) + '</span>'
            + (item.is_late ? pill('tone-bad', t('late')) : pill('tone-warn', t('waiting'))) + '</div>'
            + lines.join('')
            + '<div class="dm-card-actions"><button type="button" class="btn btn-sm btn-primary" data-dm-who="' + esc(item.task_id) + '"'
            + (busy ? ' disabled' : '') + '>' + t('who') + '</button></div>'
            + '<div data-dm-who-result>' + (result || '') + '</div></div>';
    }

    function candidatesHtml(answer) {
        if (!answer.candidates.length) {
            return '<p class="dm-note">' + esc(answer.nobody || S.nobody || '') + '</p>';
        }

        var rows = answer.candidates.map(function (row) {
            var where = row.distance_km !== null
                ? t('km', { km: row.distance_km })
                : t('no_live');
            var load = row.cap ? t('of_cap', { held: row.orders_in_hand, cap: row.cap }) : esc(row.orders_in_hand);
            var action = '';

            if (can.assign) {
                action = '<form method="POST" action="' + esc(answer.assign_url) + '" data-dm-assign>'
                    + '<input type="hidden" name="_token" value="' + esc(config.csrf) + '">'
                    + '<input type="hidden" name="driver_id" value="' + esc(row.id) + '">'
                    + '<input type="hidden" name="rest_of_order" value="1" data-dm-rest-value>'
                    + '<button type="submit" class="btn btn-sm btn-outline-primary">' + t('assign') + '</button>'
                    + '</form>';
            }

            return '<li class="dm-candidate"><span><strong>' + esc(row.name) + '</strong>'
                + '<span class="dm-leg-line">' + where + ' · ' + t('in_hand') + ': ' + load + '</span></span>'
                + action + '</li>';
        }).join('');

        var rest = can.assign
            ? '<label class="dm-rest"><input type="checkbox" checked data-dm-rest> ' + t('rest') + '</label>'
            : '';

        return rest + '<ul class="dm-candidates">' + rows + '</ul>';
    }

    // ------------------------------------------------------------- filtering

    function haystack(driver) {
        return [driver.name, driver.phone, driver.plate]
            .concat(driver.legs.map(function (leg) { return leg.order_code; }))
            .filter(Boolean).join(' ').toLowerCase();
    }

    function passes(driver) {
        var f = state.filter;

        if (f === 'signal_lost' && !driver.signal_lost) return false;
        if (f === 'late' && !driver.is_late) return false;
        if (['on_job', 'available', 'unavailable', 'inactive'].indexOf(f) !== -1 && driver.status !== f) return false;
        if (state.zone && driver.zone_ids.indexOf(Number(state.zone)) === -1) return false;
        if (state.term && haystack(driver).indexOf(state.term) === -1) return false;

        return true;
    }

    function rank(driver) {
        if (driver.signal_lost) return 0;
        if (driver.is_late) return 1;
        return { on_job: 2, available: 3, unavailable: 4, inactive: 5 }[driver.status] || 6;
    }

    // ---------------------------------------------------------------- render

    function renderCounts() {
        Object.keys(state.counts).forEach(function (key) {
            var node = root.querySelector('[data-dm-count="' + key + '"]');
            if (node) node.textContent = state.counts[key];
        });

        var lost = state.counts.signal_lost || 0;
        els.lost.hidden = lost === 0 || state.filter === 'signal_lost';
        els.lost.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + t('lost_banner', { count: lost });
    }

    function renderList() {
        var shown = state.drivers.filter(passes).sort(function (a, b) {
            return rank(a) - rank(b) || String(a.name).localeCompare(String(b.name));
        });

        els.list.innerHTML = shown.map(function (driver) {
            // One order is often two legs in hand (the pickup and the run to
            // the laundry): name each order once.
            var codes = [];
            driver.legs.forEach(function (leg) {
                if (codes.indexOf('#' + leg.order_code) === -1) codes.push('#' + leg.order_code);
            });

            var sub = codes.length
                ? codes.join(' · ')
                : (driver.location ? (driver.signal === 'live' ? S.live : (S.stale || '').split(':time').join(driver.location.seen)) : S.none);
            var flags = pill(statusTone(driver.status), t(driver.status));

            if (driver.signal_lost) flags += pill('tone-bad', t('signal_lost'));
            else if (driver.is_late) flags += pill('tone-bad', t('late'));

            var open = state.expanded === driver.id;

            return '<li class="dm-row' + (state.selected === driver.id ? ' selected' : '') + '" data-dm-driver="' + esc(driver.id) + '">'
                + '<button type="button" class="dm-row-head" aria-expanded="' + (open ? 'true' : 'false') + '">'
                + '<span class="dm-dot dm-dot--' + esc(driver.status) + '" aria-hidden="true"></span>'
                + '<span><span class="dm-row-name">' + esc(driver.name) + '</span>'
                + '<span class="dm-row-sub">' + esc(sub) + '</span></span>'
                + '<span class="dm-row-flags">' + flags + '</span></button>'
                + '<div class="dm-row-body"' + (open ? '' : ' hidden') + '>' + (open ? driverCard(driver) : '') + '</div>'
                + '</li>';
        }).join('');

        els.empty.hidden = shown.length > 0;
    }

    function renderDrivers() {
        var seen = {};

        // Removing a marker whose card is open closes the card, and closing
        // it reports back here — `unselected()` must not redraw mid-loop.
        state.rendering = true;

        state.drivers.forEach(function (driver) {
            var marker = driverMarkers[driver.id];
            var visible = driver.location && passes(driver);

            seen[driver.id] = true;

            if (!visible) {
                if (marker) {
                    delete driverMarkers[driver.id];
                    layers.drivers.removeLayer(marker);
                }
                return;
            }

            var latlng = [driver.location.lat, driver.location.lng];

            if (!marker) {
                var id = driver.id;

                marker = L.marker(latlng, { icon: driverIcon(driver), riseOnHover: true });
                marker.bindPopup(driverCard(driver), { maxWidth: 320, maxHeight: 380, autoPanPadding: [24, 24] });
                // The selection follows the card: Leaflet opens and closes it
                // on a click, and the list and the line follow that.
                marker.on('popupopen', function () { selected(id); });
                marker.on('popupclose', function () { unselected(id); });
                marker.addTo(layers.drivers);
                driverMarkers[driver.id] = marker;
            } else {
                marker.setLatLng(latlng);
                marker.setIcon(driverIcon(driver));
                marker.setPopupContent(driverCard(driver));
            }

            marker.setZIndexOffset(driver.signal_lost ? 1000 : (driver.status === 'on_job' ? 500 : 0));
        });

        Object.keys(driverMarkers).forEach(function (id) {
            if (!seen[id]) {
                var gone = driverMarkers[id];
                delete driverMarkers[id];
                layers.drivers.removeLayer(gone);
            }
        });

        state.rendering = false;
    }

    function renderWaiting() {
        if (!can.waiting) return;

        var seen = {};

        state.waiting.forEach(function (item) {
            if (!item.point || (state.zone && String(item.zone_id) !== String(state.zone))) return;

            var key = String(item.task_id);
            var marker = waitingMarkers[key];
            var icon = L.divIcon({
                className: 'dm-wait-pin' + (item.is_late ? ' dm-wait-pin--late' : ''),
                html: '<span class="dm-wait-pin-shape"></span>',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
                popupAnchor: [0, -12],
            });

            seen[key] = true;

            if (!marker) {
                marker = L.marker([item.point.lat, item.point.lng], { icon: icon, zIndexOffset: 200 });
                marker.bindPopup(waitingCard(item), { maxWidth: 340, maxHeight: 380, autoPanPadding: [24, 24] });
                marker.addTo(layers.waiting);
                waitingMarkers[key] = marker;
            } else {
                marker.setLatLng([item.point.lat, item.point.lng]);
                marker.setIcon(icon);
                // An open card keeps its candidate list; it is redrawn the
                // next time it is opened.
                if (!marker.isPopupOpen()) marker.setPopupContent(waitingCard(item));
            }
        });

        Object.keys(waitingMarkers).forEach(function (key) {
            if (!seen[key]) {
                layers.waiting.removeLayer(waitingMarkers[key]);
                delete waitingMarkers[key];
            }
        });
    }

    /** The selected driver's line to where their current leg is going. */
    function renderRoute() {
        layers.route.clearLayers();

        var driver = findDriver(state.selected);
        var leg = driver && driver.legs[0];

        if (!driver || !driver.location || !leg || !leg.destination_point) return;

        var to = [leg.destination_point.lat, leg.destination_point.lng];

        L.polyline([[driver.location.lat, driver.location.lng], to], {
            color: '#1d4ed8', weight: 3, dashArray: '6 8', opacity: .85, interactive: false,
        }).addTo(layers.route);

        var target = L.marker(to, {
            icon: L.divIcon({ className: 'dm-destination', html: '<span class="dm-destination-shape"></span>', iconSize: [16, 16], iconAnchor: [8, 8] }),
            zIndexOffset: 300,
        }).addTo(layers.route);

        var label = document.createElement('span');
        label.textContent = (leg.destination || '') + ' · #' + (leg.order_code || '');
        target.bindTooltip(label);
    }

    function render() {
        renderCounts();
        // Markers before the list: a marker filtered away closes its card,
        // which clears the selection the list then draws.
        renderDrivers();
        renderWaiting();
        renderList();
        renderRoute();
    }

    function findDriver(id) {
        for (var i = 0; i < state.drivers.length; i++) {
            if (state.drivers[i].id === id) return state.drivers[i];
        }
        return null;
    }

    function fitEverything() {
        var bounds = L.latLngBounds([]);

        Object.keys(driverMarkers).forEach(function (id) { bounds.extend(driverMarkers[id].getLatLng()); });
        Object.keys(waitingMarkers).forEach(function (id) { bounds.extend(waitingMarkers[id].getLatLng()); });

        if (!bounds.isValid()) {
            layers.zones.eachLayer(function (layer) { bounds.extend(layer.getBounds()); });
        }

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
        }
    }

    // ------------------------------------------------------------ selecting

    /** From the list: open the driver's card on the map, or in the list. */
    function select(id) {
        var marker = driverMarkers[id];

        if (marker) {
            map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 15), { duration: .6 });
            marker.openPopup(); // → selected()
            return;
        }

        // Nowhere to point at: the card opens in the list instead.
        selected(id);
    }

    /** The card is open — mark the row, draw the line. */
    function selected(id) {
        state.selected = id;
        // The card opens on the map when the driver is on it; the list only
        // unfolds for a driver it cannot point at.
        state.expanded = driverMarkers[id] ? null : id;

        renderList();
        renderDrivers();
        renderRoute();

        var row = els.list.querySelector('[data-dm-driver="' + id + '"]');
        if (row && row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
    }

    function unselected(id) {
        if (state.selected !== id) return;

        state.selected = null;
        // Mid-redraw (a filtered-out marker took its card with it): the redraw
        // in progress finishes the job.
        if (state.rendering) return;

        renderList();
        renderDrivers();
        renderRoute();
    }

    els.list.addEventListener('click', function (event) {
        var head = event.target.closest('.dm-row-head');
        if (!head) return;

        var id = Number(head.parentNode.getAttribute('data-dm-driver'));

        // A second click on the open row folds it.
        if (state.expanded === id) {
            state.expanded = null;
            if (driverMarkers[id]) driverMarkers[id].closePopup();
            unselected(id);
            renderList();
            return;
        }

        select(id);
    });

    // ------------------------------------------------------- waiting → who

    root.addEventListener('click', function (event) {
        var who = event.target.closest('[data-dm-who]');
        if (!who) return;

        var id = who.getAttribute('data-dm-who');
        var marker = waitingMarkers[id];
        var item = null;

        state.waiting.forEach(function (row) { if (String(row.task_id) === id) item = row; });
        if (!marker || !item) return;

        // Through setPopupContent rather than into the DOM: Leaflet re-measures
        // the card and pans it into view, and a card that grew past the top of
        // the map is half a card.
        function show(html, busy) {
            marker.setPopupContent(waitingCard(item, html, busy));
        }

        show('<p class="dm-note">' + t('loading') + '</p>', true);

        fetch(config.candidates.replace('__TASK__', encodeURIComponent(id)), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            if (response.status === 404) return { gone: true };
            if (!response.ok) throw new Error(String(response.status));
            return response.json();
        }).then(function (answer) {
            show(answer.gone ? '<p class="dm-note">' + t('gone') + '</p>' : candidatesHtml(answer), false);
        }).catch(function () {
            show('<p class="dm-note">' + t('failed') + '</p>', false);
        });
    });

    // «And the rest of the order» — one box for every assign form in the card.
    root.addEventListener('change', function (event) {
        if (!event.target.matches('[data-dm-rest]')) return;

        var card = event.target.closest('[data-dm-waiting]');
        if (!card) return;

        Array.prototype.forEach.call(card.querySelectorAll('[data-dm-rest-value]'), function (input) {
            input.value = event.target.checked ? '1' : '0';
        });
    });

    // Leaflet stops events inside a popup from reaching the map, but not a
    // form's submit; the default POST is what we want. Only guard against a
    // double click sending it twice.
    root.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-dm-assign]')) return;
        var button = event.target.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
    });

    // --------------------------------------------------------------- inputs

    Array.prototype.forEach.call(root.querySelectorAll('[data-dm-filter]'), function (chip) {
        chip.addEventListener('click', function () {
            setFilter(chip.getAttribute('data-dm-filter'));
        });
    });

    function setFilter(key) {
        state.filter = key;

        Array.prototype.forEach.call(root.querySelectorAll('[data-dm-filter]'), function (chip) {
            var on = chip.getAttribute('data-dm-filter') === key;
            chip.classList.toggle('active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        render();
    }

    els.lost.addEventListener('click', function () { setFilter('signal_lost'); });

    function onSearch() {
        state.term = els.search.value.trim().toLowerCase();
        render();
    }

    // `input` alone: it fires for typing, pasting and clearing, and a second
    // listener on `keyup` redrew everything twice per key.
    els.search.addEventListener('input', onSearch);
    els.search.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();

        var first = els.list.querySelector('[data-dm-driver]');
        if (first) select(Number(first.getAttribute('data-dm-driver')));
    });

    // select2 turns every .form-select into its own widget and announces a
    // change through jQuery, which addEventListener does not hear.
    function onZone() {
        state.zone = els.zone.value;
        render();

        var polygon = state.zone ? zonePolygons[state.zone] : null;
        if (polygon) map.fitBounds(polygon.getBounds(), { padding: [30, 30] });
    }

    if (window.jQuery) {
        window.jQuery(els.zone).on('change', onZone);
    } else {
        els.zone.addEventListener('change', onZone);
    }

    // -------------------------------------------------------------- polling

    function stamp() {
        if (!els.updated) return;

        if (state.stopped) {
            els.updated.textContent = (S.expired || '');
            els.updated.classList.add('is-error');
            return;
        }

        if (state.failures > 0) {
            els.updated.textContent = (S.retrying || '');
            els.updated.classList.add('is-error');
            return;
        }

        if (!state.lastOk) return;

        var time = state.lastOk.toLocaleTimeString(document.documentElement.lang || undefined, {
            hour: 'numeric', minute: '2-digit', second: '2-digit', numberingSystem: 'latn',
        });

        els.updated.textContent = (S.updated || ':time').split(':time').join(time);
        els.updated.classList.remove('is-error');
    }

    function schedule(seconds) {
        clearTimeout(state.timer);
        if (state.stopped || document.hidden) return;
        state.timer = setTimeout(load, seconds * 1000);
    }

    function load() {
        fetch(config.endpoint, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            if (response.status === 401 || response.status === 419 || response.status === 403) {
                state.stopped = true;
                throw new Error('stopped');
            }
            if (!response.ok) throw new Error(String(response.status));
            return response.json();
        }).then(function (data) {
            state.drivers = data.drivers || [];
            state.waiting = data.waiting || [];
            state.counts = data.counts || {};
            state.failures = 0;
            state.lastOk = new Date();

            render();

            if (state.firstFit) {
                state.firstFit = false;
                fitEverything();
            }

            stamp();
            schedule(data.poll_after_seconds || config.poll || 10);
        }).catch(function () {
            if (!state.stopped) state.failures++;
            stamp();
            // Back off while the server is unreachable, up to a minute.
            schedule(Math.min(60, (config.poll || 10) * Math.pow(2, Math.min(state.failures, 3))));
        });
    }

    // A hidden tab asks nothing; coming back asks at once.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearTimeout(state.timer);
        } else if (!state.stopped) {
            load();
        }
    });

    // The card above the map is the panel's own; Leaflet needs to measure the
    // box after the sidebar has settled.
    setTimeout(function () { map.invalidateSize(); }, 200);

    load();
})();
