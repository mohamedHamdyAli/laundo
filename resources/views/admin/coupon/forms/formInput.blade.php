@php
    $nameT = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
    $disabled = Route::is('*.show');
@endphp

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Code') }} <span class="text-danger">*</span></label>
        <input type="text" name="code" class="form-control text-uppercase"
            placeholder="{{ __('e.g. WELCOME10') }}"
            value="{{ old('code', $row->code ?? '') }}"
            {{ Route::is('*.create') ? 'required' : '' }} {{ $disabled ? 'readonly' : '' }}>
        <small class="text-muted">{{ __('Letters, numbers, dashes and underscores') }}</small>
    </div>
</div>

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Discount Type') }} <span class="text-danger">*</span></label>
        <select name="type" id="coupon-type" class="form-select" {{ $disabled ? 'disabled' : '' }}>
            <option value="fixed" {{ old('type', $row->type ?? '') === 'fixed' ? 'selected' : '' }}>
                {{ __('Fixed amount') }}
            </option>
            <option value="percentage" {{ old('type', $row->type ?? '') === 'percentage' ? 'selected' : '' }}>
                {{ __('Percentage') }}
            </option>
        </select>
    </div>
</div>

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Value') }} <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0.01" name="value" class="form-control"
            value="{{ old('value', $row->value ?? '') }}"
            {{ Route::is('*.create') ? 'required' : '' }} {{ $disabled ? 'readonly' : '' }}>
        <small class="text-muted" id="value-hint"></small>
    </div>
</div>

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Maximum Discount') }}</label>
        <input type="number" step="0.01" min="0" name="max_discount" class="form-control"
            value="{{ old('max_discount', $row->max_discount ?? '') }}" {{ $disabled ? 'readonly' : '' }}>
        {{-- A percentage without a ceiling is an open cheque on a large order. --}}
        <small class="text-muted">{{ __('Caps a percentage on large orders') }}</small>
    </div>
</div>

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Minimum Order') }}</label>
        <input type="number" step="0.01" min="0" name="min_order_total" class="form-control"
            value="{{ old('min_order_total', $row->min_order_total ?? '') }}" {{ $disabled ? 'readonly' : '' }}>
        <small class="text-muted">{{ __('Leave empty for no minimum') }}</small>
    </div>
</div>

<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label">{{ __('Status') }}</label>
        <select name="status" class="form-select" {{ $disabled ? 'disabled' : '' }}>
            <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                {{ __('Active') }}
            </option>
            <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                {{ __('Inactive') }}
            </option>
        </select>
    </div>
</div>

<div class="col-lg-3">
    <div class="mb-3">
        <label class="form-label">{{ __('Total Uses Allowed') }}</label>
        <input type="number" min="1" name="max_redemptions" class="form-control"
            value="{{ old('max_redemptions', $row->max_redemptions ?? '') }}" {{ $disabled ? 'readonly' : '' }}>
        <small class="text-muted">{{ __('What the campaign may cost') }}</small>
    </div>
</div>

<div class="col-lg-3">
    <div class="mb-3">
        <label class="form-label">{{ __('Uses Per Customer') }} <span class="text-danger">*</span></label>
        <input type="number" min="1" max="1000" name="max_per_user" class="form-control"
            value="{{ old('max_per_user', $row->max_per_user ?? 1) }}"
            {{ Route::is('*.create') ? 'required' : '' }} {{ $disabled ? 'readonly' : '' }}>
        {{-- The two caps answer different questions: one is what a campaign may
             cost, the other is whether one person can drain it. --}}
        <small class="text-muted">{{ __('Stops one person draining it') }}</small>
    </div>
</div>

<div class="col-lg-3">
    <div class="mb-3">
        <label class="form-label">{{ __('Starts At') }}</label>
        <input type="datetime-local" name="starts_at" class="form-control"
            value="{{ old('starts_at', isset($row->starts_at) ? $row->starts_at->format('Y-m-d\TH:i') : '') }}"
            {{ $disabled ? 'readonly' : '' }}>
    </div>
</div>

<div class="col-lg-3">
    <div class="mb-3">
        <label class="form-label">{{ __('Ends At') }}</label>
        <input type="datetime-local" name="ends_at" class="form-control"
            value="{{ old('ends_at', isset($row->ends_at) ? $row->ends_at->format('Y-m-d\TH:i') : '') }}"
            {{ $disabled ? 'readonly' : '' }}>
    </div>
</div>

<div class="col-12">
    <div class="form-check mb-3">
        <input type="hidden" name="applies_to_delivery" value="0">
        <input type="checkbox" name="applies_to_delivery" value="1" class="form-check-input"
            id="applies-delivery"
            {{ old('applies_to_delivery', $row->applies_to_delivery ?? false) ? 'checked' : '' }}
            {{ $disabled ? 'disabled' : '' }}>
        <label class="form-check-label" for="applies-delivery">
            {{ __('Also discount the delivery fee') }}
        </label>
        {{-- Free delivery and a discount on the cleaning are different products. --}}
        <small class="text-muted d-block">
            {{ __('Leave off to discount the cleaning only') }}
        </small>
    </div>
</div>

{{-- «الكوبون على إيه» — the whole order, orders of some services, or only the
     pieces in some categories or some particular pieces. An offer's discount is
     its coupon, so this is also what an offer applies to. Three lists, one per
     kind, and only the chosen one is posted: the others are disabled. --}}
@php
    $scopeType = old('scope_type', $row->scope_type ?? '');
    $scopeIds = array_map('intval', (array) old('scope_ids', $row->scope_ids ?? []));
@endphp
<div class="col-lg-4">
    <div class="mb-3">
        <label class="form-label" for="coupon-scope-type">{{ __('Applies to') }}</label>
        <select name="scope_type" id="coupon-scope-type" class="form-select" {{ $disabled ? 'disabled' : '' }}>
            <option value="" @selected($scopeType === '' || $scopeType === null)>{{ __('The whole order') }}</option>
            <option value="service" @selected($scopeType === 'service')>{{ __('Orders of particular services') }}</option>
            <option value="category" @selected($scopeType === 'category')>{{ __('The pieces in particular categories') }}</option>
            <option value="item" @selected($scopeType === 'item')>{{ __('Particular pieces') }}</option>
        </select>
        <small class="text-muted d-block">{{ __('The discount comes off only what it applies to.') }}</small>
    </div>
</div>

@foreach ([
    'service' => [__('Services'), $scopeChoices['service'] ?? collect()],
    'category' => [__('Categories'), $scopeChoices['category'] ?? collect()],
    'item' => [__('Pieces'), $scopeChoices['item'] ?? collect()],
] as $kind => [$label, $choices])
    <div class="col-lg-8 coupon-scope-values" data-scope-for="{{ $kind }}" @if ($scopeType !== $kind) hidden @endif>
        <div class="mb-3">
            <label class="form-label" for="coupon-scope-{{ $kind }}">{{ $label }}</label>
            <select name="scope_ids[]" id="coupon-scope-{{ $kind }}" class="form-select" multiple
                data-placeholder="{{ __('Choose one or more') }}"
                @if ($scopeType !== $kind || $disabled) disabled @endif>
                @foreach ($choices as $choice)
                    <option value="{{ $choice->id }}" @selected($scopeType === $kind && in_array((int) $choice->id, $scopeIds, true))>
                        {{ getLocalizedValueDashboard($choice, 'name') }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
@endforeach

@push('scripts')
    <script>
        $(function () {
            // Only the chosen kind's list is shown and posted. jQuery's `.on()`
            // because select2 announces a change with a jQuery event a native
            // listener never hears.
            function syncScope() {
                var kind = $('#coupon-scope-type').val();

                $('.coupon-scope-values').each(function () {
                    var mine = this.dataset.scopeFor === kind;
                    this.hidden = !mine;
                    $(this).find('select').prop('disabled', !mine || {{ $disabled ? 'true' : 'false' }});
                });

                // A discount on some pieces has nothing to do with the journey.
                // The box is cleared while it cannot apply and put back as it
                // was if somebody changes their mind — a click on the wrong
                // option must not quietly switch the delivery discount off.
                var pieces = kind === 'category' || kind === 'item';
                var delivery = $('#applies-delivery');

                if (pieces) {
                    if (delivery.data('was') === undefined) {
                        delivery.data('was', delivery.prop('checked'));
                    }
                    delivery.prop('checked', false);
                } else if (delivery.data('was') !== undefined) {
                    delivery.prop('checked', delivery.data('was'));
                    delivery.removeData('was');
                }
                delivery.prop('disabled', pieces || {{ $disabled ? 'true' : 'false' }});
            }

            $('#coupon-scope-type').on('change', syncScope);
            syncScope();
        });
    </script>
@endpush

{{-- «مين يشيل الخصم». A money term: it moves the cost of a campaign onto the
     laundries or off them, so it is shown — and saved — only for somebody who
     may set a laundry's share. Everyone else edits the coupon and leaves this as
     it was. --}}
@if (canDo('setting.update'))
    @php
        $storedShare = isset($row) ? $row->discount_laundry_share : null;
        $storedBearer = match (true) {
            $storedShare === null => 'default',
            (float) $storedShare === 0.0 => 'platform',
            (float) $storedShare === 100.0 => 'laundry',
            default => 'split',
        };
        $bearer = old('discount_bearer', $storedBearer);
    @endphp
    <div class="col-lg-4">
        <div class="mb-3">
            <label class="form-label" for="coupon-discount-bearer">{{ __('Who pays for the discount') }}</label>
            <select name="discount_bearer" id="coupon-discount-bearer" class="form-select" {{ $disabled ? 'disabled' : '' }}>
                <option value="default" @selected($bearer === 'default')>{{ __('The general setting') }}</option>
                <option value="platform" @selected($bearer === 'platform')>{{ __('The super admin') }}</option>
                <option value="laundry" @selected($bearer === 'laundry')>{{ __('The laundry') }}</option>
                <option value="split" @selected($bearer === 'split')>{{ __('Split between them') }}</option>
            </select>
            <small class="text-muted d-block">
                {{ __('Only the part that comes off the pieces. A discount on the delivery fee is always the super admin\'s.') }}
            </small>
        </div>
    </div>
    <div class="col-lg-3" id="coupon-discount-share-field">
        <div class="mb-3">
            <label class="form-label" for="coupon-discount-share">{{ __('The laundry pays') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="discount_laundry_share"
                    id="coupon-discount-share" class="form-control"
                    value="{{ old('discount_laundry_share', $bearer === 'split' ? $storedShare : '') }}"
                    {{ $disabled ? 'readonly' : '' }}>
                <span class="input-group-text">%</span>
            </div>
            <small class="text-muted d-block">{{ __('of the discount; the super admin pays the rest.') }}</small>
        </div>
    </div>

    @push('scripts')
        <script>
            $(document).ready(function () {
                // The percentage only means something for a split.
                function syncBearer() {
                    $('#coupon-discount-share-field').toggle($('#coupon-discount-bearer').val() === 'split');
                }

                $('#coupon-discount-bearer').on('change', syncBearer);
                syncBearer();
            });
        </script>
    @endpush
@endif

<div class="col-12"><hr>{{ __('Name (shown to customers)') }}</div>

<div class="col-lg-6">
    <div class="mb-3">
        <label class="form-label">{{ __('Name') }}</label>
        <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control"
            placeholder="{{ __('e.g. Welcome discount') }}"
            value="{{ $nameT[getDefaultLanguage('code')] ?? '' }}" {{ $disabled ? 'disabled' : '' }}>
    </div>
</div>

@foreach (getAllLanguageWithoutDefault() as $language)
    <div class="col-lg-6">
        <div class="mb-3">
            <label class="form-label">{{ __('Name') }} ({{ $language->name }})</label>
            <input type="text" name="name[{{ $language->code }}]" class="form-control"
                value="{{ $nameT[$language->code] ?? '' }}" {{ $disabled ? 'disabled' : '' }}>
        </div>
    </div>
@endforeach

@push('scripts')
    <script>
        // The value means different things for the two types, and a form that does
        // not say so invites somebody to type 50 meaning fifty pounds.
        $(function () {
            function hint() {
                const isPercent = $('#coupon-type').val() === 'percentage';
                $('#value-hint').text(isPercent
                    ? '{{ __('Percent off, 1–100') }}'
                    : '{{ __('Amount off, in currency') }}');
            }

            $('#coupon-type').on('change', hint);
            hint();
        });
    </script>
@endpush
