@php
    // Defensive unwrap: the accessor hands back a stdClass, but a value coming
    // straight from a failed validation round-trip is still a string.
    $titleTranslations = isset($row)
        ? (is_string($row->title) ? json_decode($row->title, true) : (array) $row->title)
        : [];
    $descriptionTranslations = isset($row)
        ? (is_string($row->description) ? json_decode($row->description, true) : (array) $row->description)
        : [];
    $defaultCode = getDefaultLanguage('code');
    $currentTarget = old('target_type', $row->target_type ?? 'none');
@endphp

<div class="row g-3">
    <div class="col-md-5">
        <div class="form-group">
            <label for="offer-title" class="form-label">{{ __('Title') }}</label>
            <div class="controls">
                {{-- No `required` on either language box: the rule is «copy in at
                     least one language», which no single `required` attribute can
                     express, and putting it on the default language would have
                     the browser refuse a save the server accepts. OfferRequest
                     enforces it, with its own message. --}}
                <input type="text" name="title[{{ $defaultCode }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="offer-title"
                    placeholder="{{ __('Enter Title') }}"
                    value="{{ $titleTranslations[$defaultCode] ?? '' }}">
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="offer-sort" class="form-label">{{ __('Order') }}</label>
            <div class="controls">
                <input type="number" name="sort_order" id="offer-sort" class="form-control" min="0" step="1"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('Enter Order') }}"
                    value="{{ isset($row) ? $row->sort_order : old('sort_order', 0) }}">
                <div class="form-text">{{ __('Lowest first — the order the offers appear in.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="offer-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="offer-status" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                <div class="form-text">{{ __('Only active offers are sent to the app.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="offer-description" class="form-label">{{ __('Description') }}</label>
            <div class="controls">
                <textarea name="description[{{ $defaultCode }}]" class="form-control" rows="3"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="offer-description"
                    placeholder="{{ __('Enter Description') }}">{{ $descriptionTranslations[$defaultCode] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="offer-image" class="form-label">
                {{ __('Image') }}<span class="text-danger">*</span>
            </label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="{{ __('Image') }}" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" id="offer-image" class="form-control"
                            onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">{{ __('Upload a clear image for the Offer.') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Discount') }}</div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="form-group">
            <label for="offer-coupon" class="form-label">{{ __('Coupon') }}</label>
            <div class="controls">
                <select name="coupon_id" id="offer-coupon" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="">{{ __('No discount badge') }}</option>
                    @foreach ($coupons as $coupon)
                        <option value="{{ $coupon->id }}"
                            {{ (string) old('coupon_id', $row->coupon_id ?? '') === (string) $coupon->id ? 'selected' : '' }}>
                            {{ $coupon->code }} — {{ $coupon->discountLabel() }}
                        </option>
                    @endforeach
                </select>
                {{-- Why there is no «badge text» field: the figure is read off
                     the coupon, so a card cannot advertise 20% while the code
                     gives 15, and the badge disappears by itself once the code
                     expires or runs out. --}}
                <div class="form-text">
                    {{ __('The discount badge is taken from this coupon, and hidden while the coupon is not usable.') }}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Schedule') }}</div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="form-group">
            <label for="offer-starts" class="form-label">{{ __('Starts at') }}</label>
            <div class="controls">
                <input type="datetime-local" name="starts_at" id="offer-starts" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    value="{{ old('starts_at', isset($row) && $row->starts_at ? $row->starts_at->format('Y-m-d') . 'T' . $row->starts_at->format('H:i') : '') }}">
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="offer-ends" class="form-label">{{ __('Ends at') }}</label>
            <div class="controls">
                <input type="datetime-local" name="ends_at" id="offer-ends" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    value="{{ old('ends_at', isset($row) && $row->ends_at ? $row->ends_at->format('Y-m-d') . 'T' . $row->ends_at->format('H:i') : '') }}">
                <div class="form-text">{{ __('Leave both empty to run until switched off.') }}</div>
            </div>
        </div>
    </div>
</div>

<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Action') }}</div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="form-group">
            <label for="offer-target-type" class="form-label">{{ __('When tapped') }}</label>
            <div class="controls">
                <select name="target_type" id="offer-target-type" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    @foreach ($targetTypes as $target)
                        <option value="{{ $target->value }}"
                            {{ $currentTarget === $target->value ? 'selected' : '' }}>
                            {{ __($target->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="col-md-4" data-target-for="service">
        <div class="form-group">
            <label for="offer-target-service" class="form-label">{{ __('Service') }}</label>
            <div class="controls">
                <select name="target_value" id="offer-target-service" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="">{{ __('Select') }}</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}"
                            {{ (string) old('target_value', $row->target_value ?? '') === (string) $service->id ? 'selected' : '' }}>
                            {{ getLocalizedValueDashboard($service, 'name') }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="col-md-4" data-target-for="coupon">
        <div class="form-group">
            <label for="offer-target-coupon" class="form-label">{{ __('Discount code') }}</label>
            <div class="controls">
                <select name="target_value" id="offer-target-coupon" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="">{{ __('Select') }}</option>
                    @foreach ($coupons as $coupon)
                        <option value="{{ $coupon->code }}"
                            {{ old('target_value', $row->target_value ?? '') === $coupon->code ? 'selected' : '' }}>
                            {{ $coupon->code }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Translation') }}</div>
</div>

<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-md-4">
            <div class="form-group">
                <label for="offer-title-{{ $language->code }}" class="form-label">
                    {{ __('Title') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <input type="text" name="title[{{ $language->code }}]" class="form-control"
                        id="offer-title-{{ $language->code }}"
                        placeholder="{{ __('Enter Title') }} ({{ $language->name }})"
                        value="{{ $titleTranslations[$language->code] ?? '' }}"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="form-group">
                <label for="offer-description-{{ $language->code }}" class="form-label">
                    {{ __('Description') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="description[{{ $language->code }}]" class="form-control" rows="3"
                        id="offer-description-{{ $language->code }}"
                        placeholder="{{ __('Enter Description') }} ({{ $language->name }})"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>{{ $descriptionTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>

@push('scripts')
    <script>
        // Only one target field at a time, and only the visible one submits.
        // Both share the name `target_value`, so the hidden one has to be
        // disabled rather than merely hidden — a hidden-but-enabled select still
        // posts, and whichever came last in the document would win.
        (function() {
            const kind = document.getElementById('offer-target-type');
            if (!kind) return;

            const panels = document.querySelectorAll('[data-target-for]');
            const locked = {{ Route::is('*.show') ? 'true' : 'false' }};

            function sync() {
                panels.forEach(function(panel) {
                    const shown = panel.dataset.targetFor === kind.value;
                    panel.hidden = !shown;
                    panel.querySelectorAll('select').forEach(function(field) {
                        field.disabled = locked || !shown;
                    });
                });
            }

            kind.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
