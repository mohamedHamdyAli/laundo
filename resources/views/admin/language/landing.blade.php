{{--
    The landing page's copy, for whoever is writing it.

    Same store as «Edit Web Json» — `{code}_web.json` — and deliberately not a
    second content system. What differs is the presentation: grouped by section
    in page order, readable headings, the shipped English default as the
    placeholder, and the key shown as a hint instead of an editable input. The
    flat editor is still there for the app override keys it was built for.

    Every box may be left empty. `webText()` falls back to the shipped default
    for a missing key, so blank is a reset rather than a blank space on the
    page — the callout below says so, because "clear the field" reading as
    "delete the text from the site" is the obvious wrong guess.
--}}
@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-1">
                {{ __('Edit Landing Page Content') }} —
                <span class="text-primary">{{ $language->name }}</span>
            </h5>
            <p class="text-muted small mb-0">{{ $language->code }}_web.json</p>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ url('/'.$language->code) }}" class="btn-quiet" target="_blank" rel="noopener">
                <i class="fa fa-external-link-alt me-1"></i>{{ __('View the page') }}
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
                    <i class="fa fa-circle-info me-1"></i>
                    {{ __('Leave a field empty to fall back to the default wording. Nothing on the page goes blank.') }}
                </div>

                <form action="{{ route('admin.language.landing.update', $language->id) }}" method="POST">
                    @csrf

                    @foreach ($groups as $group)
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0">{{ $group['label'] }}</h6>
                            </div>
                            <div class="card-body">
                                @foreach ($group['rows'] as $row)
                                    <div class="form-group mb-3">
                                        <label class="form-label" for="{{ $row['key'] }}">
                                            <code class="text-muted small">{{ $row['name'] }}</code>
                                        </label>

                                        @if ($row['long'])
                                            <textarea
                                                id="{{ $row['key'] }}"
                                                name="landing[{{ $row['key'] }}]"
                                                class="form-control"
                                                rows="2"
                                                dir="auto"
                                                placeholder="{{ $row['default'] }}">{{ $row['value'] }}</textarea>
                                        @else
                                            <input
                                                type="text"
                                                id="{{ $row['key'] }}"
                                                name="landing[{{ $row['key'] }}]"
                                                class="form-control"
                                                dir="auto"
                                                value="{{ $row['value'] }}"
                                                placeholder="{{ $row['default'] }}">
                                        @endif
                                    </div>
                                @endforeach
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
