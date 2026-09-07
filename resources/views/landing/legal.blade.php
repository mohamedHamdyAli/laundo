{{--
    «الشروط والأحكام» / «سياسة الخصوصية».

    The footer of a commercial site links to these, and until now they existed
    only as `Terms` and `Privacy_Policy` settings rows — readable through the
    API and editable in the panel, with nothing rendering them on the web.

    The stored copy on this install opens with "DRAFT — pending legal review",
    and this publishes it verbatim. Dressing draft terms up as finished ones
    would be the fabrication; showing what is actually stored means the state of
    it is visible to whoever looks, and replacing it is a settings edit rather
    than a deploy.

    `{!! !!}` because these values are written by the panel's rich-text editors
    and are HTML by design — the same reason `AppSettingController` documents
    them as "a wall of HTML the client must render as such". The only writers
    are `setting.update` holders.
--}}
@extends('layouts.landing')

@section('content')
    <article class="legal">
        <div class="container container--narrow">

            <a class="legal-back" href="{{ url('/'.$language->code) }}">
                <x-landing.icon name="arrow" :size="17" class="ic legal-back-icon" />
                {{ webText('landing.legal.back') }}
            </a>

            <h1 class="legal-title">{{ $title }}</h1>

            @if (filled($body))
                <div class="legal-body">{!! $body !!}</div>
            @else
                <p class="legal-empty">{{ webText('landing.legal.empty') }}</p>
            @endif

        </div>
    </article>
@endsection
