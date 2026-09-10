@extends('layouts.auth')

@section('title', __('Login'))
@section('heading', __('Operations panel'))

{{-- Says whose door it is. «Welcome back» greeted somebody without telling
     them anything, and a laundry owner handed an account had no way to know
     from this page whether it was theirs — the other door is linked below the
     card for exactly that reason. --}}
@section('subtitle', __('For Laundo administrators and operations staff. Orders, drivers, laundries and money, all from here.'))

@section('form')
    @include('auth.partials._credentials')
@endsection
