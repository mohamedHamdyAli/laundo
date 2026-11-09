@extends('layouts.main')

{{--
    «إنشاء طلب» — an order taken for a customer (2026-11-08).

    The app's own checkout behind it (`PanelOrders` → `OrderService`), so
    everything the app refuses is refused here, with the same words, beside the
    field it is about (`form-validation.js` paints the 422).

    The basket is drawn from the app's price list (`$catalog`) and posted as
    hidden `items[n][item_id|qty]` rows rebuilt on every change, so a piece left
    at 0 is never sent. The price box asks the server (`admin.order.quote`) —
    never sums anything itself — so what the operator reads to the customer is
    what will be stored.
--}}

@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('New order for a customer') }}</h5>
        <a href="{{ route('admin.order.index') }}" class="btn btn-sm btn-light">{{ __('Back to orders') }}</a>
    </div>

    <form class="needs-validation" id="po-form" action="{{ route('admin.order.store') }}" method="POST"
        enctype="multipart/form-data" novalidate>
        @csrf
        @include('layouts.validateMessage.errorMessage')

        <div class="row g-3">
            <div class="col-lg-8">
                {{-- 1. Who, and where --}}
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Customer and address') }}</h6>

                        <div class="mb-3">
                            <label for="po-customer" class="form-label">{{ __('Customer') }} <span class="text-danger">*</span></label>
                            {{-- Re-initialised below over AJAX, on DOM-ready and
                                 after the panel's own initialisers (select2 replaces
                                 their plain instance). Not earlier: custom.js runs
                                 `$('.select2').select2()` on DOM-ready, and that
                                 selector matches the container select2 draws. --}}
                            <select name="user_id" id="po-customer" class="form-select"
                                data-placeholder="{{ __('Search by name, phone or bag reference...') }}">
                                <option value=""></option>
                            </select>
                            <small class="text-muted">{{ __('Registered customers only. A new customer signs up from the app first.') }}</small>
                        </div>

                        <div class="row g-3 po-needs-customer" hidden>
                            <div class="col-md-6">
                                <label for="po-pickup-address" class="form-label">{{ __('Pickup address') }} <span class="text-danger">*</span></label>
                                <select name="pickup_address_id" id="po-pickup-address" class="form-select"></select>
                            </div>
                            <div class="col-md-6">
                                <label for="po-delivery-address" class="form-label">{{ __('Delivery address') }}</label>
                                <select name="delivery_address_id" id="po-delivery-address" class="form-select"></select>
                            </div>
                            <div class="col-12">
                                <p class="po-coverage text-danger small mb-1" hidden>{{ __('This address is outside the areas we serve — the order will be refused.') }}</p>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="po-add-address">
                                    <i class="fa fa-map-marker-alt"></i> {{ __('Add a new address on the map') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 2. What --}}
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Service and pieces') }}</h6>

                        <div class="mb-3">
                            <label for="po-service" class="form-label">{{ __('Service') }} <span class="text-danger">*</span></label>
                            <select name="service_id" id="po-service" class="form-select">
                                <option value="">{{ __('Choose a service') }}</option>
                                @foreach ($services as $service)
                                    <option value="{{ $service->id }}">{{ getLocalizedValueDashboard($service, 'name') }}</option>
                                @endforeach
                            </select>
                        </div>

                        <p class="text-muted small po-quote-mode" hidden>{{ __('This service is priced after the laundry inspects the pieces — no basket is needed.') }}</p>

                        <div id="po-items" class="po-items"></div>
                        {{-- What is posted: rebuilt from the boxes above on every change. --}}
                        <div id="po-items-posted"></div>
                    </div>
                </div>

                {{-- 3. When --}}
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Pickup and delivery times') }}</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="po-pickup-date" class="form-label">{{ __('Pickup date') }}</label>
                                <input type="date" name="pickup_date" id="po-pickup-date" class="form-control"
                                    min="{{ now(displayTimezone())->toDateString() }}" value="{{ now(displayTimezone())->toDateString() }}">
                            </div>
                            <div class="col-md-6">
                                <label for="po-pickup-slot" class="form-label">{{ __('Pickup window') }}</label>
                                <select name="pickup_slot_id" id="po-pickup-slot" class="form-select">
                                    <option value="">{{ __('Choose a window') }}</option>
                                    @foreach ($pickupSlots as $slot)
                                        <option value="{{ $slot->id }}">{{ $slot->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="po-delivery-date" class="form-label">{{ __('Delivery date') }}</label>
                                <input type="date" name="delivery_date" id="po-delivery-date" class="form-control"
                                    min="{{ now(displayTimezone())->toDateString() }}">
                            </div>
                            <div class="col-md-6">
                                <label for="po-delivery-slot" class="form-label">{{ __('Delivery window') }}</label>
                                <select name="delivery_slot_id" id="po-delivery-slot" class="form-select">
                                    <option value="">{{ __('Choose a window') }}</option>
                                    @foreach ($deliverySlots as $slot)
                                        <option value="{{ $slot->id }}">{{ $slot->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="po-pickup-method" class="form-label">{{ __('At the pickup') }}</label>
                                <select name="pickup_method" id="po-pickup-method" class="form-select">
                                    <option value="door">{{ __('Hand it over at the door') }}</option>
                                    <option value="leave">{{ __('Collect it from outside the door') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="po-delivery-method" class="form-label">{{ __('At the delivery') }}</label>
                                <select name="delivery_method" id="po-delivery-method" class="form-select">
                                    <option value="door">{{ __('Hand it over at the door') }}</option>
                                    <option value="leave">{{ __('Leave it at the door') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 4. Notes --}}
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Notes') }}</h6>
                        <div class="mb-3">
                            <label for="po-driver-note" class="form-label">{{ __('Note for the driver') }}</label>
                            <textarea name="driver_note" id="po-driver-note" class="form-control" rows="2" maxlength="1000"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="po-instructions" class="form-label">{{ __('Special instructions') }}</label>
                            <textarea name="special_instructions" id="po-instructions" class="form-control" rows="2" maxlength="2000"></textarea>
                        </div>
                        <div>
                            <label for="po-photos" class="form-label">{{ __('Photos of difficult stains') }}</label>
                            <input type="file" name="photos[]" id="po-photos" class="form-control" multiple accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">{{ __('Up to 5 images.') }}</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                {{-- 5. Payment and discount — the app's own choices. --}}
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Payment and discount') }}</h6>
                        <div class="mb-3">
                            <label for="po-payment" class="form-label">{{ __('Payment method') }}</label>
                            <select name="payment_method" id="po-payment" class="form-select">
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method->value }}" @selected($method->value === 'cash')>{{ __($method->label()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="po-offer" class="form-label">{{ __('Offer') }}</label>
                            <select name="offer_id" id="po-offer" class="form-select">
                                <option value="">{{ __('No offer') }}</option>
                                @foreach ($offers as $offer)
                                    <option value="{{ $offer->id }}">{{ getLocalizedValueDashboard($offer, 'title') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="po-coupon" class="form-label">{{ __('Coupon code') }}</label>
                            <input type="text" name="coupon_code" id="po-coupon" class="form-control" maxlength="50" autocomplete="off">
                            <small class="text-muted">{{ __('One discount per order: an offer and a code cannot be used together.') }}</small>
                        </div>
                        @if ($pointsEnabled)
                            <div class="form-check">
                                <input type="hidden" name="use_points" value="0">
                                <input class="form-check-input" type="checkbox" name="use_points" value="1" id="po-points">
                                <label class="form-check-label" for="po-points">
                                    {{ __('Use the points this customer holds') }}
                                    <span class="text-muted po-points-available"></span>
                                </label>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- 6. The price, from the server. --}}
                <div class="card po-summary">
                    <div class="card-body">
                        <h6 class="mb-3">{{ __('Price') }}</h6>
                        <p class="text-muted small po-summary-empty mb-0">{{ __('Choose the customer, an address and a service to see the price.') }}</p>
                        <dl class="po-summary-rows mb-2" hidden></dl>
                        <p class="po-summary-total fw-bold mb-1" hidden></p>
                        <p class="po-summary-laundry text-muted small mb-1" hidden></p>
                        <p class="po-summary-error text-danger small mb-0" hidden></p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="accepts_review_terms" value="1" id="po-terms">
                            <label class="form-check-label" for="po-terms">
                                {{ __('The customer agrees that the pieces are reviewed and the final price set before cleaning.') }}
                            </label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">{{ __('Place the order') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- A new address for the chosen customer. Outside the order form: a form
         cannot hold another, and this one posts on its own. --}}
    <div class="modal fade" id="po-address-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form id="po-address-form" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New address for the customer') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger po-address-errors" hidden></div>
                        <div class="row g-3">
                            <div class="col-12">
                                <x-map-picker lat-input="po-address-lat" lng-input="po-address-lng" inputs="hidden"
                                    height="300px" :label="__('Location on the map')" />
                                <div class="mb-3">
                                    <input type="hidden" id="po-address-lat" name="lat">
                                </div>
                                <div class="mb-3">
                                    <input type="hidden" id="po-address-lng" name="lng">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="po-address-label" class="form-label">{{ __('Label') }}</label>
                                <input type="text" name="label" id="po-address-label" class="form-control" maxlength="191" placeholder="{{ __('Home, work...') }}">
                            </div>
                            <div class="col-md-6">
                                <label for="po-address-street" class="form-label">{{ __('Street') }} <span class="text-danger">*</span></label>
                                <input type="text" name="street" id="po-address-street" class="form-control" maxlength="500">
                            </div>
                            <div class="col-md-4">
                                <label for="po-address-building" class="form-label">{{ __('Building') }}</label>
                                <input type="text" name="building" id="po-address-building" class="form-control" maxlength="50">
                            </div>
                            <div class="col-md-4">
                                <label for="po-address-floor" class="form-label">{{ __('Floor') }}</label>
                                <input type="text" name="floor" id="po-address-floor" class="form-control" maxlength="50">
                            </div>
                            <div class="col-md-4">
                                <label for="po-address-apartment" class="form-label">{{ __('Apartment') }}</label>
                                <input type="text" name="apartment" id="po-address-apartment" class="form-control" maxlength="50">
                            </div>
                            <div class="col-md-6">
                                <label for="po-address-landmark" class="form-label">{{ __('Landmark') }}</label>
                                <input type="text" name="landmark" id="po-address-landmark" class="form-control" maxlength="191">
                            </div>
                            <div class="col-md-6">
                                <label for="po-address-phone" class="form-label">{{ __('Contact phone') }}</label>
                                <input type="text" name="contact_phone" id="po-address-phone" class="form-control" placeholder="+201012345678" dir="ltr">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save the address') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script type="application/json" id="po-catalog">@json($catalog)</script>
    @php
        $poConfig = [
            'customersUrl' => route('admin.order.customers'),
            'addressesUrl' => route('admin.order.customer_addresses', ['id' => '__ID__']),
            'quoteUrl' => route('admin.order.quote'),
            'slotsUrl' => route('api.v1.time-slots'),
            'text' => [
                'sameAddress' => __('Same as the pickup address'),
                'noAddresses' => __('This customer has no saved address yet — add one on the map.'),
                'outside' => __('outside coverage'),
                'default' => __('default'),
                'full' => __('full'),
                'closed' => __('closed'),
                'outsideDays' => __('outside the delivery days'),
                'pointsAvailable' => __('(:points available)'),
                'quoting' => __('Working out the price...'),
                'laundry' => __('Laundry: :name'),
                'unpriced' => __('Some pieces are not available for this service.'),
                'quoteFailed' => __('The price could not be worked out — check the highlighted fields.'),
                'pieces' => __('Pieces'),
            ],
        ];
    @endphp
    <script type="application/json" id="po-config">@json($poConfig)</script>
@endsection

@push('styles')
    <style>
        .po-items .po-category { font-weight: 600; margin: .75rem 0 .25rem; }
        .po-items .po-item { display: flex; align-items: center; gap: .75rem; padding: .25rem 0; border-bottom: 1px solid var(--surface-border, rgba(0,0,0,.06)); }
        .po-items .po-item-name { flex: 1 1 auto; }
        .po-items .po-item-price { color: var(--bs-secondary-color, #6c757d); white-space: nowrap; }
        .po-items .po-item-qty { width: 5.5rem; flex: 0 0 auto; }
        .po-summary-rows { display: grid; grid-template-columns: 1fr auto; gap: .25rem .75rem; }
        .po-summary-rows dt { font-weight: 400; }
        .po-summary-rows dd { margin: 0; text-align: end; }
    </style>
@endpush

@push('scripts')
    <script>
        // On DOM-ready, after footer_script's and custom.js's own select2 passes
        // (registered first, so run first) — see the customer field above.
        $(function () {
            var config = JSON.parse(document.getElementById('po-config').textContent);
            var catalog = JSON.parse(document.getElementById('po-catalog').textContent);
            var form = document.getElementById('po-form');
            var t = config.text;

            var $customer = $('#po-customer');
            var $pickup = $('#po-pickup-address');
            var $delivery = $('#po-delivery-address');
            var $service = $('#po-service');
            var addresses = [];

            function esc(value) {
                return String(value === null || value === undefined ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // --- the customer: searched on the server, as the operator types.
            $customer.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $customer.data('placeholder'),
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: config.customersUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { query: params.term }; },
                    processResults: function (data) { return data; },
                },
            });

            // jQuery `.on('change')`: select2 announces with a jQuery event,
            // which `addEventListener` never hears (tasks/lessons.md).
            $customer.on('change', function () {
                var id = $customer.val();
                document.querySelectorAll('.po-needs-customer').forEach(function (el) { el.hidden = !id; });
                addresses = [];
                fillAddresses(null);
                if (id) loadAddresses(id, null);
                requote();
            });

            function addressText(a) {
                var text = (a.label ? a.label + ' — ' : '') + (a.line || '');
                if (a.zone) text += ' (' + a.zone + ')';
                if (a.is_default) text += ' · ' + t.default;
                if (!a.is_covered) text += ' · ' + t.outside;
                return text;
            }

            function fillAddresses(selectId) {
                var pickupHtml = '';
                var deliveryHtml = '<option value="">' + esc(t.sameAddress) + '</option>';

                addresses.forEach(function (a) {
                    var option = '<option value="' + a.id + '">' + esc(addressText(a)) + '</option>';
                    pickupHtml += option;
                    deliveryHtml += option;
                });

                if (addresses.length === 0) {
                    pickupHtml = '<option value="">' + esc(t.noAddresses) + '</option>';
                }

                $pickup.html(pickupHtml);
                $delivery.html(deliveryHtml);

                var chosen = selectId || (addresses[0] ? addresses[0].id : '');
                $pickup.val(chosen ? String(chosen) : '').trigger('change.select2');
                $delivery.val('').trigger('change.select2');
                showCoverage();
            }

            function loadAddresses(customerId, selectId) {
                fetch(config.addressesUrl.replace('__ID__', encodeURIComponent(customerId)), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function (r) { return r.ok ? r.json() : { addresses: [] }; })
                    .then(function (data) {
                        // A late answer for a customer no longer chosen must not
                        // fill the next customer's address list.
                        if (String($customer.val()) !== String(customerId)) return;
                        addresses = data.addresses || [];
                        fillAddresses(selectId);
                        requote();
                        refreshSlots();
                    });
            }

            function showCoverage() {
                var ids = [$pickup.val(), $delivery.val()].filter(Boolean);
                var outside = addresses.some(function (a) {
                    return ids.indexOf(String(a.id)) !== -1 && !a.is_covered;
                });
                document.querySelector('.po-coverage').hidden = !outside;
            }

            $pickup.on('change', function () { showCoverage(); requote(); refreshSlots(); });
            $delivery.on('change', function () { showCoverage(); requote(); });

            // --- the basket, from the app's price list for the chosen service.
            function serviceEntry() {
                var id = parseInt($service.val(), 10);
                return catalog.find(function (s) { return s.id === id; }) || null;
            }

            function drawItems() {
                var entry = serviceEntry();
                var box = document.getElementById('po-items');
                var quoteMode = entry && entry.pricing_mode !== 'per_item';
                document.querySelector('.po-quote-mode').hidden = !quoteMode;

                if (!entry || quoteMode) {
                    box.innerHTML = '';
                    syncItems();
                    return;
                }

                var html = '';
                entry.categories.forEach(function (category) {
                    html += '<div class="po-category">' + esc(category.name) + '</div>';
                    category.items.forEach(function (item) {
                        html += '<div class="po-item">'
                            + '<span class="po-item-name">' + esc(item.name) + '</span>'
                            + '<span class="po-item-price">' + esc(item.price) + '</span>'
                            + '<input type="number" min="0" max="999" step="1" value="0" class="form-control form-control-sm po-item-qty"'
                            + ' data-item-id="' + item.id + '" aria-label="' + esc(t.pieces + ': ' + item.name) + '">'
                            + '</div>';
                    });
                });
                box.innerHTML = html;
                syncItems();
            }

            // Only the pieces with a count go out — `items[n][item_id|qty]`.
            // An empty basket still posts `items` as present-but-empty for a
            // quote-priced service (the request reads a missing key as []).
            function syncItems() {
                var posted = document.getElementById('po-items-posted');
                var html = '';
                var n = 0;
                document.querySelectorAll('#po-items .po-item-qty').forEach(function (input) {
                    var qty = parseInt(input.value, 10);
                    if (qty > 0) {
                        html += '<input type="hidden" name="items[' + n + '][item_id]" value="' + parseInt(input.dataset.itemId, 10) + '">'
                            + '<input type="hidden" name="items[' + n + '][qty]" value="' + Math.min(qty, 999) + '">';
                        n++;
                    }
                });
                posted.innerHTML = html;
            }

            $service.on('change', function () { drawItems(); requote(); refreshSlots(); });
            // A pickup window without room for this many pieces is marked full
            // (item 19) — asked again when the basket changes.
            var piecesTimer = null;
            document.getElementById('po-items').addEventListener('input', function () {
                syncItems(); requote();
                clearTimeout(piecesTimer);
                piecesTimer = setTimeout(function () {
                    markSlots('pickup', '#po-pickup-slot', document.getElementById('po-pickup-date').value);
                }, 400);
            });

            function basketPieces() {
                var total = 0;
                document.querySelectorAll('#po-items .po-item-qty').forEach(function (input) {
                    var qty = parseInt(input.value, 10);
                    if (qty > 0) total += Math.min(qty, 999);
                });
                return total;
            }

            // --- windows: the app's own list for the day, so a closed or full
            // window is marked before the order is refused for it.
            function refreshSlots() {
                markSlots('pickup', '#po-pickup-slot', document.getElementById('po-pickup-date').value);
                markSlots('delivery', '#po-delivery-slot', document.getElementById('po-delivery-date').value);
            }

            function markSlots(type, selector, date) {
                var $select = $(selector);
                $select.find('option').each(function () {
                    var $o = $(this);
                    if (!$o.data('label')) $o.data('label', $o.text());
                    $o.prop('disabled', false).text($o.data('label'));
                });
                if (!date) { $select.trigger('change.select2'); return; }

                var params = new URLSearchParams({ type: type, date: date });
                if ($service.val()) params.set('service_id', $service.val());
                if (type === 'pickup' && basketPieces() > 0) params.set('pieces', basketPieces());
                if (type === 'delivery') {
                    var pickupDate = document.getElementById('po-pickup-date').value;
                    if (pickupDate) params.set('pickup_date', pickupDate);
                    if ($('#po-pickup-slot').val()) params.set('pickup_slot_id', $('#po-pickup-slot').val());
                }

                fetch(config.slotsUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : { data: [] }; })
                    .then(function (body) {
                        (body.data || []).forEach(function (slot) {
                            var $o = $select.find('option[value="' + slot.id + '"]');
                            // A delivery window before the service's time is up,
                            // or past the last delivery day, is refused on submit
                            // (`Turnaround`) — say so before.
                            var why = slot.closed ? t.closed
                                : (slot.is_full ? t.full
                                : ((slot.too_early || slot.too_late) ? t.outsideDays : null));
                            if (why && $o.length) {
                                $o.prop('disabled', true).text($o.data('label') + ' — ' + why);
                                if ($select.val() === String(slot.id)) $select.val('');
                            }
                        });
                        $select.trigger('change.select2');
                    });
            }

            ['po-pickup-date', 'po-delivery-date'].forEach(function (id) {
                document.getElementById(id).addEventListener('change', function () { refreshSlots(); requote(); });
            });
            $('#po-pickup-slot').on('change', function () { markSlots('delivery', '#po-delivery-slot', document.getElementById('po-delivery-date').value); requote(); });
            $('#po-payment, #po-offer').on('change', requote);
            document.getElementById('po-coupon').addEventListener('input', requote);
            var points = document.getElementById('po-points');
            if (points) points.addEventListener('change', requote);

            // --- the price box: asked of the server, debounced.
            var quoteTimer = null;
            var quoteSeq = 0;

            function requote() {
                clearTimeout(quoteTimer);
                quoteTimer = setTimeout(runQuote, 400);
            }

            function summary(selector) { return document.querySelector('.po-summary ' + selector); }

            function runQuote() {
                var ready = $customer.val() && $pickup.val() && $service.val();
                summary('.po-summary-empty').hidden = !!ready;
                ['.po-summary-rows', '.po-summary-total', '.po-summary-laundry', '.po-summary-error'].forEach(function (s) {
                    summary(s).hidden = true;
                });
                if (!ready) return;

                var data = new FormData(form);
                // The price does not depend on the photos; do not upload them
                // on every keystroke.
                data.delete('photos[]');
                var seq = ++quoteSeq;
                summary('.po-summary-error').hidden = false;
                summary('.po-summary-error').classList.replace('text-danger', 'text-muted');
                summary('.po-summary-error').textContent = t.quoting;

                fetch(config.quoteUrl, {
                    method: 'POST',
                    body: data,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                    .then(function (res) {
                        if (seq !== quoteSeq) return;
                        var error = summary('.po-summary-error');
                        error.classList.replace('text-muted', 'text-danger');

                        if (!res.ok) {
                            var first = res.body && res.body.errors ? Object.values(res.body.errors)[0] : null;
                            error.textContent = (first && first[0]) || t.quoteFailed;
                            return;
                        }

                        var q = res.body;
                        var rows = summary('.po-summary-rows');
                        rows.innerHTML = q.rows.map(function (row) {
                            return '<dt>' + esc(row.label) + '</dt><dd>' + esc(row.value) + '</dd>';
                        }).join('');
                        rows.hidden = false;

                        var total = summary('.po-summary-total');
                        total.textContent = q.total;
                        total.hidden = false;

                        var laundry = summary('.po-summary-laundry');
                        laundry.textContent = q.laundry ? t.laundry.replace(':name', q.laundry) : '';
                        laundry.hidden = !q.laundry;

                        var problems = [];
                        if (q.unpriced) problems.push(t.unpriced);
                        if (q.coupon_error) problems.push(q.coupon_error);
                        error.textContent = problems.join(' ');
                        error.hidden = problems.length === 0;

                        var available = document.querySelector('.po-points-available');
                        if (available) available.textContent = t.pointsAvailable.replace(':points', q.points_available);
                    })
                    .catch(function () {
                        if (seq !== quoteSeq) return;
                        summary('.po-summary-error').textContent = t.quoteFailed;
                    });
            }

            // --- a new address, saved to the customer and chosen for the pickup.
            var modalEl = document.getElementById('po-address-modal');
            var addressForm = document.getElementById('po-address-form');

            document.getElementById('po-add-address').addEventListener('click', function () {
                addressForm.reset();
                modalEl.querySelector('.po-address-errors').hidden = true;
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });

            // Leaflet measured a hidden box when the page loaded; the picker
            // re-measures on resize.
            modalEl.addEventListener('shown.bs.modal', function () {
                window.dispatchEvent(new Event('resize'));
            });

            addressForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var customerId = $customer.val();
                if (!customerId) return;

                var errors = modalEl.querySelector('.po-address-errors');
                var data = new FormData(addressForm);
                data.append('_token', form.querySelector('input[name="_token"]').value);

                fetch(config.addressesUrl.replace('__ID__', encodeURIComponent(customerId)), {
                    method: 'POST',
                    body: data,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            var list = res.body && res.body.errors
                                ? Object.values(res.body.errors).map(function (m) { return m[0]; })
                                : [res.body && res.body.message ? res.body.message : t.quoteFailed];
                            errors.innerHTML = list.map(function (m) { return '<div>' + esc(m) + '</div>'; }).join('');
                            errors.hidden = false;
                            return;
                        }
                        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                        loadAddresses(customerId, res.body.address.id);
                    });
            });

            drawItems();
            refreshSlots();
        });
    </script>
@endpush
