@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <code class="fs-5">{{ $row->code }}</code>
            <span class="badge {{ $row->status === 'active' ? 'bg-success' : 'bg-secondary' }} ms-2">
                {{ __(ucfirst($row->status)) }}
            </span>
        </h5>
        <a href="{{ route('admin.coupon.index') }}" class="badge alert-secondary">
            <i class="fa fa-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Terms') }}</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            @include('admin.coupon.forms.formInput')
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">{{ __('Who has used it') }}</h6>
                        <span class="badge bg-secondary">
                            {{ $row->redemptions_count }}
                            @if ($row->max_redemptions) / {{ $row->max_redemptions }} @endif
                        </span>
                    </div>
                    <div class="card-body">
                        @if ($redemptions->isEmpty())
                            <p class="text-muted mb-0">{{ __('Nobody has used this code yet.') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ __('Customer') }}</th>
                                            <th>{{ __('Order') }}</th>
                                            <th>{{ __('Discount') }}</th>
                                            <th>{{ __('When') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($redemptions as $redemption)
                                            <tr>
                                                <td>
                                                    {{ $redemption->customer?->name ?? '—' }}
                                                    <small class="text-muted d-block">
                                                        {{ $redemption->customer?->phone }}
                                                    </small>
                                                </td>
                                                <td>
                                                    @if ($redemption->order)
                                                        <a href="{{ route('admin.order.show', $redemption->order->id) }}">
                                                            #{{ $redemption->order->code }}
                                                        </a>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td>{{ moneyFormat($redemption->amount) }}</td>
                                                <td>{{ humanDate($redemption->created_at) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
