@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Add Discount Code') }}</h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <form class="row g-3 needs-validation store" action="{{ route('admin.coupon.store') }}"
                        method="POST">
                        @csrf
                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.coupon.forms.formInput')
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Add') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
