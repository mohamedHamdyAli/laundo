@extends('layouts.main')

@section('content')
    <div class="card-header align-items-center d-flex">
        <h4 class="card-title mb-0 flex-grow-1">{{ __('Edit Category') }}</h4>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">

                <div class="card-body">

                    <div class="live-preview">
                        <form class="row g-3 needs-validation store" action="{{ route('admin.category.update', $row->id) }}"
                            method="Post" enctype="multipart/form-data" id="form_with_disabled" novalidate>
                            @csrf
                            @method('PUT')
                            @include('layouts.validateMessage.errorMessage')
                            @include('admin.category.forms.formInput')

                            <div class="col-12 d-flex justify-content-center">
                                <button class="btn btn-primary mt-3 mb-2 font-weight-bold" style="width: 25%;"
                                    type="submit">
                                    {{ __('Update') }}
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
