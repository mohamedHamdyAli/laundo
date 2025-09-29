@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h4 class="card-title mb-0 flex-grow-1">{{ __('Add City') }}</h4>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">

                    <div class="live-preview">
                        <form class="row g-3 needs-validation store" action="{{ route('admin.city.store') }}" method="POST"
                            enctype="multipart/form-data">
                            @csrf
                            @include('layouts.validateMessage.errorMessage')
                            @include('admin.city.forms.formInput')
                            <div class="col-12">
                                <button class="btn btn-primary mt-3 mb-2"style="display: block;width: 10%;"
                                    type="submit">{{ __('Add') }}</button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
