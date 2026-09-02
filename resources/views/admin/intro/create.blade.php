@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Add Intro') }}</h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">

                    <form class="row g-3 needs-validation store" action="{{ route('admin.intro.store') }}" method="POST"
                        enctype="multipart/form-data">
                        @csrf
                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.intro.forms.formInput')
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Add') }}</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
