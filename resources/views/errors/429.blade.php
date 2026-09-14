@extends('errors.layout')

@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('You have sent a lot of requests in a short time. Wait a moment and try again.'))
