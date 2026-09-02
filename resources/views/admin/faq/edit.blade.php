@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">{{ __('Edit Question') }}</h5>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <form class="row g-3 needs-validation update" action="{{ route('admin.faq.update', $row->id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        @include('layouts.validateMessage.errorMessage')
                        @include('admin.faq.forms.formInput')
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">{{ __('Update') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
