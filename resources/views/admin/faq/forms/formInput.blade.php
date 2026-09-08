@php
    // The accessors return a stdClass, and a fresh create has no $row at all —
    // both cases have to land on a plain array before the inputs read from it.
    $questionTranslations = isset($row)
        ? (is_string($row->question) ? json_decode($row->question, true) : (array) $row->question)
        : [];
    $answerTranslations = isset($row)
        ? (is_string($row->answer) ? json_decode($row->answer, true) : (array) $row->answer)
        : [];
    $readOnly = Route::is('*.show');
    $defaultCode = getDefaultLanguage('code');
@endphp

{{--
    No `required` on the default-language boxes, deliberately.

    `FaqRequest` asks for **at least one** language rather than all of them, and a
    plain `required` attribute cannot express that — the browser would block a
    submit the server would have accepted. CLAUDE.md states the pairing: when the
    server rule is relaxed to "at least one", the client attribute has to come off
    with it.
--}}

<div class="row g-3">
    <div class="col-md-12">
        <div class="form-group">
            <label for="faq-question" class="form-label">{{ __('Question') }}</label>
            <div class="controls">
                <input type="text" name="question[{{ getDefaultLanguage('code') }}]" class="form-control"
                    id="faq-question" placeholder="{{ __('Enter Question') }}" {{ $readOnly ? 'disabled' : '' }}
                    value="{{ $questionTranslations[getDefaultLanguage('code')] ?? '' }}">
            </div>
        </div>
    </div>

    <div class="col-md-12">
        <div class="form-group">
            <label for="faq-answer" class="form-label">{{ __('Answer') }}</label>
            <div class="controls">
                {{-- A textarea, not an input: an answer that has to fit on one
                     line is an answer nobody can write. --}}
                <textarea name="answer[{{ getDefaultLanguage('code') }}]" class="form-control" id="faq-answer"
                    rows="5" placeholder="{{ __('Enter Answer') }}"
                    {{ $readOnly ? 'disabled' : '' }}>{{ $answerTranslations[getDefaultLanguage('code')] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="faq-audience" class="form-label">{{ __('Shown to') }}</label>
            <div class="controls">
                {{-- Both apps list «الأسئلة الشائعة» and the answers are not the
                     same: a driver asking when they get paid and a customer asking
                     when they get their clothes should not read each other's list. --}}
                <select name="audience" id="faq-audience" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="both" @selected(($row->audience ?? 'both') === 'both')>{{ __('Both apps') }}</option>
                    <option value="customer" @selected(($row->audience ?? '') === 'customer')>{{ __('Customers') }}</option>
                    <option value="driver" @selected(($row->audience ?? '') === 'driver')>{{ __('Drivers') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="faq-order" class="form-label">{{ __('Order') }}</label>
            <div class="controls">
                <input type="number" name="order" id="faq-order" class="form-control" min="0" max="9999"
                    {{ $readOnly ? 'disabled' : '' }} value="{{ $row->order ?? 0 }}">
                <small class="text-muted">{{ __('Lower numbers appear first.') }}</small>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="active" @selected(($row->status ?? 'active') === 'active')>{{ __('active') }}</option>
                    <option value="inactive" @selected(($row->status ?? '') === 'inactive')>{{ __('inactive') }}</option>
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
        <div class="col-md-12">
            <div class="form-group">
                <label for="faq-question-{{ $language->code }}" class="form-label">
                    {{ __('Question') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <input type="text" name="question[{{ $language->code }}]" class="form-control"
                        id="faq-question-{{ $language->code }}"
                        placeholder="{{ __('Enter Question') }} ({{ $language->name }})"
                        value="{{ $questionTranslations[$language->code] ?? '' }}"
                        {{ $readOnly ? 'disabled' : '' }}>
                </div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                <label for="faq-answer-{{ $language->code }}" class="form-label">
                    {{ __('Answer') }} ({{ $language->name }})
                </label>
                <div class="controls">
                    <textarea name="answer[{{ $language->code }}]" class="form-control" rows="5"
                        id="faq-answer-{{ $language->code }}"
                        placeholder="{{ __('Enter Answer') }} ({{ $language->name }})"
                        {{ $readOnly ? 'disabled' : '' }}>{{ $answerTranslations[$language->code] ?? '' }}</textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>
