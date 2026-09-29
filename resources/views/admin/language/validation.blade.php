{{--
    What a refused request is told, for one language — «Edit Validation Messages».

    The store is `{code}_validation.json`, laid over the shipped
    `lang/{code}/validation.php` (ValidationOverrideLoader). So, as on the
    landing screen, every box starts empty with the shipped wording as its
    placeholder, and a box emptied again is a reset to that wording.

    Two things the page says on its face, because each is the obvious wrong guess:
    - the words starting with a colon are filled in when the message is shown,
      and a message that loses one is refused on save;
    - a field's name here is what it is called *inside* a message — it is not a
      label on any form.

    `needs-validation`, so a refused save paints the message beside the box and
    keeps everything else typed. A filter box rather than a search endpoint:
    this is one form posting every row, and a re-render would drop the rows it
    did not draw.
--}}
@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-1">
                {{ __('Edit Validation Messages') }} —
                <span class="text-primary">{{ $language->name }}</span>
            </h5>
            <p class="text-muted small mb-0">{{ $language->code }}_validation.json</p>
        </div>

        <a href="{{ route('admin.language.index') }}" class="btn-quiet">
            <i class="fa fa-arrow-left me-1"></i>{{ __('Back to Languages') }}
        </a>
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
                    {{ __('Words that start with a colon, like :attribute or :min, are replaced when the message is shown — keep them.') }}
                </div>

                <div class="list-toolbar">
                    <input type="text" id="validationFilterInput" class="form-control list-toolbar-search"
                        placeholder="{{ __('Filter messages...') }}" autocomplete="off">
                    <span class="text-muted small align-self-center" id="validationFilterInput-count"></span>
                </div>
                <div id="validationFilterInput-empty" class="stack-empty" style="display: none">{{ __('No data found') }}</div>

                @php $old = (array) old('messages', []); @endphp

                <form class="needs-validation" novalidate
                    action="{{ route('admin.language.validation.update', $language->id) }}" method="POST">
                    @csrf

                    @foreach ($groups as $group)
                        <div class="card mb-3" data-filter-group>
                            <div class="card-header">
                                <h6 class="card-title mb-1">{{ $group['label'] }}</h6>
                                <p class="text-muted small mb-0">{{ $group['hint'] }}</p>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach ($group['rows'] as $row)
                                        @php
                                            $id = 'v-'.str_replace('.', '-', $row['key']);
                                            // `array_key_exists`, not `??`: a box cleared to reset it
                                            // comes back from a refused save as null, and `??` would
                                            // put the saved words back into it.
                                            $value = array_key_exists($row['key'], $old) ? (string) $old[$row['key']] : $row['value'];
                                        @endphp
                                        <div class="{{ str_starts_with($row['key'], 'attributes.') ? 'col-md-6' : 'col-12' }} form-group mb-3" data-filter-item>
                                            <label class="form-label d-flex flex-wrap gap-2 align-items-baseline" for="{{ $id }}">
                                                <code class="text-muted small">{{ $row['key'] }}</code>
                                                @foreach ($row['tokens'] as $token)
                                                    <span class="badge bg-light text-dark border" dir="ltr">{{ $token }}</span>
                                                @endforeach
                                                {{-- The filter reads a row's text, and the wording is in the
                                                     input's placeholder and value, which are not text — so
                                                     without this only the English key could be found. --}}
                                                <span class="visually-hidden">{{ $row['default'] }} {{ $row['value'] }}</span>
                                            </label>

                                            <input
                                                type="text"
                                                id="{{ $id }}"
                                                name="messages[{{ $row['key'] }}]"
                                                class="form-control"
                                                dir="auto"
                                                value="{{ $value }}"
                                                placeholder="{{ $row['default'] }}">

                                            {{-- Once a box holds its own words the placeholder is
                                                 gone, and with it the only sight of what a reset
                                                 would bring back. --}}
                                            @if ($value !== '')
                                                <div class="form-text" dir="auto">{{ __('Default') }}: {{ $row['default'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach

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
            inputSelector: '#validationFilterInput',
            itemSelector: '[data-filter-item]',
            groupSelector: '[data-filter-group]',
            emptySelector: '#validationFilterInput-empty',
            countSelector: '#validationFilterInput-count',
        });
    </script>
@endpush
