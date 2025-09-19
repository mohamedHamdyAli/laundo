@extends('layouts.main')

@section('content')
    <div class="card-header align-items-center d-flex">
        <h4 class="card-title mb-0 flex-grow-1">{{ __('Show Category') }}</h4>
        <div class="flex-shrink-0">
            <div class="form-check form-switch form-switch-right form-switch-md">

            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <div class="live-preview show-page">
                        @include('admin.category.forms.formInput')
                        <a class="btn btn-warning mt-3 mb-2 font-weight-bold d-block mx-auto" style="width: 25%;"
                            href="{{ URL::previous() }}">
                            Back
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
