@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ $row->name }}</h5>
        <a href="{{ route('admin.driver_application.index') }}" class="btn btn-outline-secondary btn-sm">
            {{ __('Back') }}
        </a>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('Name') }}</dt>
                            <dd class="col-sm-8">{{ $row->name }}</dd>

                            <dt class="col-sm-4">{{ __('Phone') }}</dt>
                            <dd class="col-sm-8">
                                <a href="tel:{{ $row->phone }}" dir="ltr">{{ $row->phone }}</a>
                            </dd>

                            <dt class="col-sm-4">{{ __('Applied') }}</dt>
                            <dd class="col-sm-8">{{ humanDate($row->created_at) }}</dd>

                            <dt class="col-sm-4">{{ __('Status') }}</dt>
                            <dd class="col-sm-8">
                                @if ($row->isWaiting())
                                    <span class="badge bg-warning text-dark">{{ __('Waiting') }}</span>
                                @else
                                    <span class="badge bg-success">{{ __('Handled') }}</span>
                                    @if ($row->handler)
                                        <span class="text-muted small">
                                            — {{ $row->handler->name }}, {{ humanDate($row->handled_at) }}
                                        </span>
                                    @endif
                                @endif
                            </dd>

                            @if (filled($row->note))
                                <dt class="col-sm-4">{{ __('What they wrote') }}</dt>
                                <dd class="col-sm-8">{{ $row->note }}</dd>
                            @endif

                            @if (filled($row->admin_note))
                                <dt class="col-sm-4">{{ __('Call notes') }}</dt>
                                <dd class="col-sm-8">{{ $row->admin_note }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        @if (canDo('driver_application.toggle'))
                            {{-- The note is taken with the decision rather than
                                 on a screen of its own: an operator who has to
                                 click through to record what was said will not,
                                 and «handled» with nothing behind it tells the
                                 next person nothing. --}}
                            <form method="POST"
                                action="{{ route('admin.driver_application.handled', $row->id) }}">
                                @csrf

                                @if ($row->isWaiting())
                                    <div class="mb-3">
                                        <label class="form-label" for="admin_note">{{ __('Call notes') }}</label>
                                        <textarea class="form-control" id="admin_note" name="admin_note" rows="3"
                                            maxlength="1000"
                                            placeholder="{{ __('Optional — what was agreed, or why not') }}">{{ old('admin_note', $row->admin_note) }}</textarea>
                                    </div>

                                    <button type="submit" class="btn btn-success w-100">
                                        {{ __('We called them') }}
                                    </button>
                                @else
                                    <p class="text-muted small">
                                        {{ __('Somebody has dealt with this. Put it back if it still needs a call.') }}
                                    </p>
                                    <button type="submit" class="btn btn-outline-secondary w-100">
                                        {{ __('Put back') }}
                                    </button>
                                @endif
                            </form>
                        @endif

                        @if (canDo('driver.create'))
                            <a href="{{ route('admin.driver.create') }}" class="btn btn-outline-primary w-100 mt-2">
                                {{ __('Create a driver') }}
                            </a>
                            <p class="text-muted small mt-2 mb-0">
                                {{ __('An application is a lead, not an account — the driver is created here once the call has gone well.') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
