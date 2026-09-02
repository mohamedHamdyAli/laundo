@php
    $titleTranslations = isset($row)
        ? (is_string($row->title)
            ? json_decode($row->title, true)
            : (array) $row->title)
        : [];
    $descriptionTranslations = isset($row)
        ? (is_string($row->description)
            ? json_decode($row->description, true)
            : (array) $row->description)
        : [];
    $defaultCode = getDefaultLanguage('code');
@endphp

{{--
    The three short fields on one line, the paragraph and the illustration on
    their own.

    The layout before this put Title, Description and Order at `col-md-4` each:
    a three-row textarea for a two-line paragraph squeezed into the same third
    of the card as the field for a single-digit sort number, with a single-line
    input on either side of it. The row below then mixed `col-lg-6` (image) with
    `col-md-4` (status) — ten of twelve columns, so it ended in a two-column
    hole, and the two halves of one row disagreed about which breakpoint they
    answered to.

    Now: 5 + 3 + 4 = 12 columns of same-height single-line controls, then the
    paragraph and the upload box each across the full width, which is what they
    actually need.
--}}
<div class="row g-3">
    <div class="col-md-5">
        <div class="form-group">
            <label for="intro-title" class="form-label">{{ __('Title') }}</label>
            <div class="controls">
                {{-- No `required` on either language box. The rule is «copy in
                     at least one language», which no single `required`
                     attribute can express — and putting it on the default
                     language would have the browser refuse a save the server
                     accepts. IntroRequest enforces it, with its own message. --}}
                <input type="text" name="title[{{ $defaultCode }}]" class="form-control"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="intro-title"
                    placeholder="{{ __('Enter Title') }}"
                    value="{{ $titleTranslations[$defaultCode] ?? '' }}">
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="intro-order" class="form-label">{{ __('Order') }}</label>
            <div class="controls">
                {{-- `type="text"` for an integer column validated
                     `required|integer`, so letters reached the server to be
                     rejected there instead of at the keyboard. And the
                     placeholder was two translation keys concatenated with no
                     separator, which renders as one run-together word. --}}
                <input type="number" name="order" id="intro-order" class="form-control" min="1" step="1"
                    {{ Route::is('*.show') ? 'disabled' : '' }}
                    placeholder="{{ __('Enter Order') }}"
                    value="{{ isset($row) ? $row->order : old('order') }}"
                    {{ Route::is('*.create') ? 'required' : '' }}>
                <div class="form-text">{{ __('Lowest first — the order the slides appear in.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="intro-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="intro-status" class="form-select"
                    {{ Route::is('*.show') ? 'disabled' : '' }}>
                    <option value="active" {{ isset($row) && $row->status == 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ isset($row) && $row->status == 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                <div class="form-text">{{ __('Only active slides are sent to the app.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="intro-description" class="form-label">{{ __('Description') }}</label>
            <div class="controls">
                {{-- Was `<input type="description">` — not an input type at all,
                     so the browser fell back to a single-line text box for what
                     the design lays out as a two-line paragraph. The default
                     language got that while the languages below it got
                     `type="text"`: one field, two different controls.
                     `service` and `faq` already use a textarea here. --}}
                <textarea name="description[{{ $defaultCode }}]" class="form-control" rows="3"
                    {{ Route::is('*.show') ? 'disabled' : '' }} id="intro-description"
                    placeholder="{{ __('Enter Description') }}">{{ $descriptionTranslations[$defaultCode] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group">
            <label for="intro-image" class="form-label">
                {{ __('Image') }}<span class="text-danger">*</span>
            </label>
            <div class="upload-field">
                <div class="text-center">
                    <img id="image-preview" src="{{ isset($row) && $row->image ? getImageassetUrl($row->image) : '' }}"
                        alt="{{ __('Image') }}" class="img-fluid rounded mb-2"
                        style="max-height: 150px; {{ isset($row) && $row->image ? '' : 'display:none;' }}">

                    @if (!Route::is('*.show'))
                        <input type="file" name="image" id="intro-image" class="form-control"
                            onchange="previewImage(event)"
                            {{ Route::is('*.create') ? 'required' : '' }} accept="image/*">
                        {{-- Was a bare English string, so an Arabic panel showed
                             this one line in English. --}}
                        <div class="form-text mt-2">{{ __('Upload a clear image for the Intro.') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- The legend and rule the other eight translatable modules use. This was a
     bare translated string dropped in a `col-12` — a line of text with nothing
     to say that what followed was the same fields again in another language. --}}
<div class="col-12 form-divider">
    <div class="form-section-legend">{{ __('Translation') }}</div>
</div>

<div class="row g-3">
    @foreach (getAllLanguageWithoutDefault() as $language)
        <div class="col-md-4">
            <div class="form-group">
                <label for="intro-title-{{ $language->code }}" class="form-label">
                    {{ __('Title') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <input type="text" name="title[{{ $language->code }}]" class="form-control"
                        id="intro-title-{{ $language->code }}"
                        placeholder="{{ __('Enter Title') }} ({{ $language->name }})"
                        value="{{ $titleTranslations[$language->code] ?? '' }}"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="form-group">
                {{-- The label and placeholder were bare strings while the
                     default language's were wrapped in `__()`, so switching the
                     panel to Arabic translated half of one form. --}}
                <label for="intro-description-{{ $language->code }}" class="form-label">
                    {{ __('Description') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="description[{{ $language->code }}]" class="form-control" rows="3"
                        id="intro-description-{{ $language->code }}"
                        placeholder="{{ __('Enter Description') }} ({{ $language->name }})"
                        {{ Route::is('*.show') ? 'disabled' : '' }}>{{ $descriptionTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>
