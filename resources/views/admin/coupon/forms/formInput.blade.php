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
