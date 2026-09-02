@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1"> {{ __('Show Moderator') }}
        </h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">

                    <div class="show-page">

                        @include('admin.moderator.forms.formInput')

                        <div class="form-actions">
                            <a class="btn-quiet" href="{{ URL::previous() }}">{{ __('Back') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
