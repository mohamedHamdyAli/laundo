@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">
            {{ __('Commission') }} — {{ getLocalizedValueDashboard($row, 'name') }}
        </h5>
        <a href="{{ route('admin.commission_rule.index') }}" class="btn-quiet">
            <i class="fa fa-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    {{-- The count is the question a charge page has to answer:
                         editing these terms changes what these laundries pay. --}}
                    <p class="text-muted small">
                        {{ trans_choice(':count laundry pays this charge|:count laundries pay this charge',
                            $row->laundries_count ?? 0, ['count' => $row->laundries_count ?? 0]) }}
                    </p>
                    @include('admin.commission_rule.forms.formInput')
                </div>
            </div>
        </div>
    </div>
@endsection
