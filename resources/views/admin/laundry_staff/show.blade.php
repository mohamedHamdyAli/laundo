@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h4 class="card-title mb-0 flex-grow-1">{{ __('Show Laundry Staff') }}</h4>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <div class="live-preview show-page">
                        @include('admin.laundry_staff.forms.formInput')

                        <a class="btn btn-warning mt-3 mb-2" style="display: block;width: 100%;"
                            href="{{ URL::previous() }}">{{ __('Back') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
