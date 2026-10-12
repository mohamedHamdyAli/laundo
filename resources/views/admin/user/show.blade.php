@extends('layouts.main')
@section('content')
    <div class="card-header align-items-center d-flex gap-2">
        <h5 class="card-title mb-0 flex-grow-1"> {{ __('Show User') }}
        </h5>

        {{-- «النقاط» — the balance, and the way to their ledger. Never inside a
             laundry, whatever it was granted: the accounts are every customer on
             the platform, and the points screen and its export refuse a laundry
             the same way (pointAccountCrudService, PointAccountSheet). --}}
        @if (canDo('point_account.view') && ! \App\Support\LaundryContext::isTenant() && $row->role?->slug === \App\Models\Role::USER)
            @php($points = app(\App\Modules\Loyalty\Services\PointsService::class)->available($row))
            <a href="{{ route('admin.point_account.show', $row->id) }}" class="btn-quiet">
                <i class="bi bi-stars"></i>
                {{ trans_choice(':count point|:count points', $points, ['count' => number_format($points)]) }}
            </a>
        @endif
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">

                    <div class="show-page">

                        @include('admin.user.forms.formInput')

                        <div class="form-actions">
                            <a class="btn-quiet" href="{{ URL::previous() }}">{{ __('Back') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
