@php
    $nameTranslations = isset($row) ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name) : [];
    $descriptionTranslations = isset($row)
        ? (is_string($row->description)
            ? json_decode($row->description, true)
            : (array) $row->description)
        : [];
@endphp
{{-- Title / description / Order --}}
<div class="row g-3">
    <div class="col-md-4">
        <div class="form-group">
            <label for="banner-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                <input type="text" name="name[{{ getDefaultLanguage('code') }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="banner-name" placeholder="{{ __('Enter Name') }}"
                    value="{{ $nameTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="banner-description" class="form-label">{{ __('Description') }}</label>
            <div class="controls">
                <input type="description" name="description[{{ getDefaultLanguage('code') }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="banner-description"
                    placeholder="{{ __('Enter Description') }}"
                    value="{{ $descriptionTranslations[getDefaultLanguage('code')] ?? '' }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-select" {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('active') }}</option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}</option>
                </select>
            </div>
        </div>
    </div>
    {{--
        Where «عرض التفاصيل» goes.

        The design has always had that button. Until now the table had nowhere to
        point it, so a published banner was decoration. The kind is a closed set
        rather than a free URL, so the app can route in-app and so it stays
        possible to ask whether a banner ever produced an order.
    --}}
    <div class="col-md-4">
        <div class="form-group">
            <label for="banner-target-type" class="form-label">{{ __('When tapped') }}</label>
            <div class="controls">
                <select name="target_type" id="banner-target-type" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    @foreach ($targetTypes as $case)
                        <option value="{{ $case->value }}"
                            @selected(isset($row) && $row->target_type === $case->value)>
                            {{ __($case->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="form-group" id="banner-target-service-wrap">
            <label for="banner-target-service" class="form-label">{{ __('Service to open') }}</label>
            <div class="controls">
                <select name="target_value" id="banner-target-service" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}"
                            @selected(isset($row) && $row->target_type === 'service' && (string) $row->target_value === (string) $service->id)>
                            {{ getLocalizedValueDashboard($service, 'name') }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group" id="banner-target-coupon-wrap">
            <label for="banner-target-coupon" class="form-label">{{ __('Discount code to apply') }}</label>
            <div class="controls">
                <select name="target_value" id="banner-target-coupon" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    @foreach ($coupons as $coupon)
                        <option value="{{ $coupon->code }}"
                            @selected(isset($row) && $row->target_type === 'coupon' && $row->target_value === $coupon->code)>
                            {{ $coupon->code }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="col-lg-12">
        <div class="mb-3">
            <label class="form-label">{{ __('Image') }}<span class="text-danger">*</span></label>
            <div class="card p-2 shadow-sm" style="border: 2px dashed #ddd;">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="Image Preview" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" class="form-control" onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        <div class="form-text mt-2">Upload a clear image for the Banner.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        {{ __('Translation Optional') }}
    </div>
</div>

{{-- userRole / status --}}

<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-lg-6">
            <div class="mb-3">
                <label for="banner-name-{{ $language->code }}" class="form-label">
                    name ({{ $language->name }})
                </label>
                <input type="text" name="name[{{ $language->code }}]" class="form-control"
                    id="banner-name-{{ $language->code }}" placeholder="Enter Banner name in {{ $language->name }}"
                    value="{{ $nameTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="mb-3">
                <label for="banner-description-{{ $language->code }}" class="form-label">
                    Description ({{ $language->name }})
                </label>
                <input type="text" name="description[{{ $language->code }}]" class="form-control"
                    id="banner-description-{{ $language->code }}"
                    placeholder="Enter Banner Description in {{ $language->name }}"
                    value="{{ $descriptionTranslations[$language->code] ?? '' }}"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
            </div>
        </div>
    @endforeach
</div>

@push('scripts')
    <script>
        // Two selects share the name `target_value`, so the one that is not in use
        // has to be DISABLED, not just hidden: a hidden-but-enabled select still
        // posts, and the later one would overwrite the chosen value.
        (function () {
            const type = document.getElementById('banner-target-type');
            if (!type) return;

            const panes = {
                service: document.getElementById('banner-target-service-wrap'),
                coupon: document.getElementById('banner-target-coupon-wrap'),
            };

            // On the show page every control is already disabled by Blade. Only
            // visibility is managed there, or this would re-enable the field and
            // make a read-only page editable.
            const readOnly = type.disabled;

            function sync() {
                Object.entries(panes).forEach(([kind, wrap]) => {
                    if (!wrap) return;
                    const on = type.value === kind;
                    wrap.hidden = !on;

                    if (readOnly) return;

                    wrap.querySelectorAll('select').forEach((el) => {
                        el.disabled = !on;
                    });
                });
            }

            type.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
