@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex justify-content-between">
        <h4 class="card-title mb-0">{{ __('Question') }} #{{ $row->id }}</h4>
        <a href="{{ route('admin.faq.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Back') }}</a>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    @include('admin.faq.forms.formInput')
                </div>
            </div>
        </div>
    </div>
@endsection
