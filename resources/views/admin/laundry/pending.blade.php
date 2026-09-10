@extends('layouts.main')

{{-- «مستنية موافقة» — laundries that applied through the public form.

     A screen of its own rather than a filter on the list, because the two do
     different things: every row here carries approve and reject, and no row on
     the list does. A filter that changed which buttons a row has would be a
     second screen pretending to be one.

     Everything an operator needs to decide is on the row — who applied, from
     where, how to reach them and when they asked — so the decision does not
     start with opening a detail page. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Laundry applications') }}
            @if ($laundries->total() > 0)
                <span class="badge bg-warning ms-2">{{ $laundries->total() }}</span>
            @endif
        </h5>
        <a href="{{ route('admin.laundry.index') }}" class="btn btn-outline-secondary btn-sm">
            {{ __('All laundries') }}
        </a>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @forelse ($laundries as $laundry)
                            <div class="application-row">
                                <div class="application-main">
                                    <h6 class="application-name">
                                        {{ getLocalizedValueDashboard($laundry, 'name') }}
                                    </h6>

                                    <dl class="application-facts">
                                        <div>
                                            <dt>{{ __('Owner') }}</dt>
                                            <dd>{{ $laundry->owner?->name ?: '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt>{{ __('Email') }}</dt>
                                            <dd dir="ltr">{{ $laundry->owner?->email ?: '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt>{{ __('Phone') }}</dt>
                                            <dd dir="ltr">{{ $laundry->phone }}</dd>
                                        </div>
                                        <div>
                                            <dt>{{ __('City') }}</dt>
                                            <dd>{{ $laundry->city ? getLocalizedValueDashboard($laundry->city, 'name') : '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt>{{ __('Applied') }}</dt>
                                            <dd>{{ humanDate($laundry->created_at) }}</dd>
                                        </div>
                                    </dl>

                                    @if (filled($laundry->address))
                                        <p class="application-address">{{ $laundry->address }}</p>
                                    @endif
                                </div>

                                <div class="application-actions">
                                    <a href="{{ route('admin.laundry.show', $laundry->id) }}"
                                        class="btn btn-outline-secondary btn-sm">{{ __('View') }}</a>

                                    <form method="POST" action="{{ route('admin.laundry.approve', $laundry->id) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">{{ __('Approve') }}</button>
                                    </form>

                                    {{-- The reason travels with the rejection rather than
                                         being asked for on a second screen: an operator
                                         who has to click through to explain will not, and
                                         a "no" with no reason is one the applicant cannot
                                         act on — so they simply apply again. --}}
                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                        data-bs-toggle="collapse" data-bs-target="#reject-{{ $laundry->id }}">
                                        {{ __('Reject') }}
                                    </button>
                                </div>

                                <div class="collapse application-reject" id="reject-{{ $laundry->id }}">
                                    <form method="POST" action="{{ route('admin.laundry.reject', $laundry->id) }}">
                                        @csrf
                                        <label class="form-label" for="reason-{{ $laundry->id }}">
                                            {{ __('Why? This is emailed to them.') }}
                                        </label>
                                        <div class="d-flex gap-2">
                                            <input type="text" class="form-control" maxlength="1000"
                                                id="reason-{{ $laundry->id }}" name="rejection_reason"
                                                placeholder="{{ __('Optional — e.g. we could not verify the address') }}">
                                            <button type="submit" class="btn btn-danger">{{ __('Confirm') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="text-center text-muted my-5 mb-0">
                                {{ __('No laundry is waiting to be reviewed.') }}
                            </p>
                        @endforelse

                        <div class="mt-3">{{ $laundries->links() }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
