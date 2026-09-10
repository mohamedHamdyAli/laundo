{{--
    The answer to a laundry's application.

    One template for both outcomes rather than two, because everything except
    the middle paragraph is identical and two files drift. The rejection carries
    the reason when one was given: «no» with no reason is a message the reader
    can do nothing with, and they will simply apply again.
--}}
<x-mail::message>
# {{ $approved ? __('Welcome to Laundo') : __('About your application') }}

@if ($approved)
{{ __('Good news — :name has been approved and your laundry panel is open.', ['name' => $laundryName]) }}

{{ __('Sign in with the email and password you chose when you applied.') }}

<x-mail::button :url="$signInUrl">
{{ __('Sign in to your laundry') }}
</x-mail::button>

{{ __('The first things to set are the services you offer and the areas you cover — orders only reach a laundry that has both.') }}
@else
{{ __('Thank you for your interest in Laundo. We are not able to approve :name at the moment.', ['name' => $laundryName]) }}

@if (filled($reason))
{{ __('The reason given was:') }}

> {{ $reason }}

{{ __('You are welcome to apply again once that is sorted.') }}
@else
{{ __('You are welcome to apply again, or reply to this email if you would like to know more.') }}
@endif
@endif

{{ __('Thanks') }},<br>
{{ getSettingValue('App_Name') ?: config('app.name') }}
</x-mail::message>
