{{--
    The one that matters.

    A 500 is most often a database that is unreachable, and on this install the
    cache and the session are both *in* that database — so this page renders with
    nothing behind it. See the rule at the top of `errors/layout.blade.php`
    before adding anything here: a settings read, a `@csrf`, or an `auth()` call
    would throw while rendering, and Laravel would fall back to its bare built-in
    page. The nice design would fail in precisely the case it exists for.
--}}
@extends('errors.layout')

@section('code', '500')
@section('title', __('Something went wrong on our side'))
@section('message', __('This is a fault in the system, not something you did. It has been recorded and the team can see it.'))

@section('meta')
    {{-- The request id, so a report is one line instead of «the site broke».
         Read off the current request, which exists even when nothing else does;
         `user-select: all` in error.css makes it one click to copy. --}}
    {{ __('Reference:') }}
    <code>{{ request()->header('X-Request-Id') ?: substr(md5(microtime()), 0, 12) }}</code>
@endsection
