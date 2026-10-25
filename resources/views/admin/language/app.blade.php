{{--
    One app's words, for one language — «نصوص تطبيق العميل» / «نصوص تطبيق المندوب».

    The app team's `resources/lang/{code}_app_{app}.json` is the shipped
    wording and is never written; what is saved here is laid over it
    (AppStrings) and served nested by `GET /translations/app?app=…`. Every box
    holds the words the app is served now, so they are edited in place; words
    left as shipped store nothing, and a box emptied is a reset to the shipped
    wording (its placeholder). The keys are the app team's: this screen edits
    values only.

    Two things the page says on its face, because each is the obvious wrong guess:
    - `{}` and `{name}` are filled in by the app, and a placeholder English
      does not have is refused on save;
    - a key is a dotted path into the app's file (`login.title`) — what a
      screen calls it, not where it appears.

    `needs-validation`, so a refused save paints the message beside the box and
    keeps everything else typed. A filter box rather than a search endpoint:
    this is one form posting every row, and a re-render would drop the rows it
    did not draw.
--}}
@extends('layouts.main')

@section('content')
    @php
        $title = $app === 'driver' ? __('Driver app texts') : __('Customer app texts');
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-1">
                {{ $title }} —
                <span class="text-primary">{{ $language->name }}</span>
            </h5>
            <p class="text-muted small mb-0">{{ $language->code }}_app_{{ $app }}.json</p>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('admin.language.app.download', [$language->id, $app]) }}"
                class="btn-quiet">
                <i class="fa fa-download me-1"></i>{{ __('Download JSON') }}
            </a>
            <a href="{{ route('admin.language.index') }}" class="btn-quiet">
                <i class="fa fa-arrow-left me-1"></i>{{ __('Back to Languages') }}
            </a>
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">

                @if (session('success'))
                    <div class="alert alert-success" role="alert">{{ session('success') }}</div>
                @endif

                <div class="alert alert-light border" role="note">
                    <i class="fa fa-info-circle me-1"></i>
                    {{ __('Leave a field empty to fall back to the default wording.') }}
                    {{ __('Words in braces, like {} or {name}, are filled in by the app — keep them.') }}
                    {{ __('New keys come from the app team with a new file.') }}
                </div>

                <div class="list-toolbar">
                    <input type="text" id="appStringsFilterInput" class="form-control list-toolbar-search"
                        placeholder="{{ __('Filter texts...') }}" autocomplete="off">
                    <span class="text-muted small align-self-center" id="appStringsFilterInput-count"></span>
                </div>
                <div id="appStringsFilterInput-empty" class="stack-empty" style="display: none">{{ __('No data found') }}</div>

                {{-- The background submit paints each refusal beside its box; this
                     is for the page a plain post comes back to. --}}
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php $old = (array) old('strings', []); @endphp

                <form class="needs-validation" novalidate
                    action="{{ route('admin.language.app.update', [$language->id, $app]) }}" method="POST">
                    @csrf

                    @foreach ($groups as $group)
                        <div class="card mb-3" data-filter-group>
                            <div class="card-header">
                                <h6 class="card-title mb-0"><code>{{ $group['label'] }}</code></h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach ($group['rows'] as $row)
                                        @php
                                            $id = 'a-'.str_replace('.', '-', $row['key']);
                                            // `array_key_exists`, not `??`: a box cleared on purpose
                                            // comes back from a refused save as null, and `??` would
                                            // put the saved words back into it.
                                            $value = array_key_exists($row['key'], $old) ? (string) $old[$row['key']] : $row['value'];
                                        @endphp
                                        <div class="{{ $row['multiline'] ? 'col-12' : 'col-md-6' }} form-group mb-3" data-filter-item>
                                            <label class="form-label" for="{{ $id }}">
                                                <code class="text-muted small">{{ $row['key'] }}</code>
                                                {{-- The filter reads a row's text, and the words are in the
                                                     input's placeholder and value, which are not text. --}}
                                                <span class="visually-hidden">{{ $row['default'] }} {{ $row['value'] }}</span>
                                            </label>

                                            @if ($row['multiline'])
                                                <textarea id="{{ $id }}" name="strings[{{ $row['key'] }}]" class="form-control"
                                                    dir="auto" rows="3" placeholder="{{ $row['default'] }}">{{ $value }}</textarea>
                                            @else
                                                <input type="text" id="{{ $id }}" name="strings[{{ $row['key'] }}]"
                                                    class="form-control" dir="auto" value="{{ $value }}"
                                                    placeholder="{{ $row['default'] }}">
                                            @endif

                                            {{-- Once a box holds its own words the placeholder is
                                                 gone, and with it the only sight of what a reset
                                                 would bring back. --}}
                                            @if ($value !== '' && $value !== $row['default'])
                                                <div class="form-text" dir="auto">{{ __('Default') }}: {{ $row['default'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach

                    {{-- Last on purpose: see LanguageController::updateApp(). --}}
                    <input type="hidden" name="complete" value="1">

                    <div class="form-actions d-flex justify-content-end gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i>{{ __('Save Changes') }}
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        setupClientFilter({
            inputSelector: '#appStringsFilterInput',
            itemSelector: '[data-filter-item]',
            groupSelector: '[data-filter-group]',
            emptySelector: '#appStringsFilterInput-empty',
            countSelector: '#appStringsFilterInput-count',
        });
    </script>
@endpush
