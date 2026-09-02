@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Edit Laundry') }}</h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <form class="row g-3 needs-validation store" action="{{ route('admin.laundry.update', $row->id) }}"
                        method="Post" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.laundry.forms.formInput')
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Edit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
