{{--
    The landing page.

    The order is the argument, and it is worth reading as one:

      hero        — the claim, and the card that proves it
      problem     — why the usual way is worse
      promise     — the four beats, compressed
      review      — the claim in full; the page's centre of gravity
      journey     — where that step sits in the whole order
      services    — what we clean, with real turnarounds
      offers      — whatever is live, if anything
      prices      — the published list, so the count is checkable
      coverage    — whether we reach you, and when
      features    — the smaller conveniences, quietly
      faq         — the objections, answered
      cta         — the ask
      partners    — the supply-side ask, subordinate

    Sections whose data is empty remove themselves: `_services`, `_prices`,
    `_coverage` and `_faq` each guard on their own content. That is not
    defensive padding — `faqs`, `intros`, `banners` and `order_ratings` are all
    empty in this database today, and the FAQ falls back to the Web File while
    anything with no fallback simply does not render.
--}}
@extends('layouts.landing')

@section('content')
    @include('landing.partials._hero')
    @include('landing.partials._frustration')
    @include('landing.partials._promise')
    @include('landing.partials._review_deepdive')
    @include('landing.partials._journey')
    @include('landing.partials._services')
    @include('landing.partials._offers')
    @include('landing.partials._prices')
    @include('landing.partials._coverage')
    @include('landing.partials._features')
    @include('landing.partials._faq')
    @include('landing.partials._cta')
    @include('landing.partials._partners')

    {{-- The dialog the partner card opens. Last in the document so it is never
         inside a section that could clip or transform it. --}}
    @include('landing.partials._driver_form')
@endsection
