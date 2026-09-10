@extends('layouts.auth-card')

{{-- «طلبك تحت المراجعة».

     Its own address rather than a flash message on the form, so a refresh does
     not re-submit and the applicant can be sent back here.

     It says what happens next in order, because the honest answer to "when?"
     is "a person has to look", and a page that says only «شكراً» leaves
     somebody refreshing their inbox with no idea whether anything is running. --}}

@section('title', __('Application received'))
@section('card-modifier', 'is-message')
@section('heading', __('We have your application'))
@section('subtitle', __('Our team will review it and get back to you.'))

@section('form')
    <span class="auth-seal" aria-hidden="true">
        <x-landing.icon name="check" :size="26" />
    </span>

    <ul class="auth-steps">
        <li>{{ __('Someone from Laundo reviews what you sent.') }}</li>
        <li>{{ __('We email you the moment a decision is made.') }}</li>
        <li>{{ __('Once approved, sign in and set the services you offer and the areas you cover — orders only reach a laundry that has both.') }}</li>
    </ul>
@endsection

@section('footnote')
    <a href="{{ url('/'.panelLanguageCode()) }}">{{ __('Back to the home page') }}</a>
@endsection
