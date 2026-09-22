@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex">
        <h5 class="card-title mb-0 flex-grow-1">
            {{ __('Bonus Rule') }} — {{ getLocalizedValueDashboard($row, 'name') }}
        </h5>
        <a href="{{ route('admin.driver_bonus_rule.index') }}" class="btn-quiet">
            <i class="fa fa-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    {{-- The count is the question a rule page has to answer:
                         changing these terms changes what these people are paid. --}}
                    <p class="text-muted small">
                        {{ trans_choice(':count driver is on this rule|:count drivers are on this rule',
                            $row->profiles_count ?? 0, ['count' => $row->profiles_count ?? 0]) }}
                    </p>
                    {{-- `show-page` is what turns the module's own form into a
                         read-only sheet. Without it these screens render as a
                         locked form: every control still boxed, filled and at
                         `opacity: .6`, which is the browser saying «you cannot
                         type here» on a page whose whole job is to be read. The
                         two money screens were the only ones built without it. --}}
                    <div class="show-page">
                        @include('admin.driver_bonus_rule.forms.formInput')
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
