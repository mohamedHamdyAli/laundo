@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Driver Performance') }}</h5>
        <span class="text-muted small">{{ $range->label() }}</span>
    </div>

    <section class="section">
        <div class="card mb-3">
            <div class="card-body">
                @include('admin.report.partials._range', ['exportable' => 'drivers'])
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Driver') }}</th>
                                        <th>{{ __('Tasks') }}</th>
                                        <th>{{ __('Completed') }}</th>
                                        <th>{{ __('Failed') }}</th>
                                        <th>{{ __('Late') }}</th>
                                        <th>{{ __('Avg duration') }}</th>
                                        <th class="text-end">{{ __('Earnings') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td>
                                                <strong>{{ $row['driver'] }}</strong>
                                                <small class="text-muted d-block">{{ $row['phone'] }}</small>
                                            </td>
                                            <td>{{ $row['tasks'] }}</td>
                                            <td class="text-success">
                                                {{ $row['completed'] }}
                                                <small class="text-muted d-block">
                                                    {{ $row['completion_rate'] }}%
                                                </small>
                                            </td>
                                            <td class="{{ $row['failed'] > 0 ? 'text-danger' : '' }}">
                                                {{ $row['failed'] }}
                                            </td>
                                            <td class="{{ $row['late_rate'] > 20 ? 'text-warning' : '' }}">
                                                {{ $row['late'] }}
                                                <small class="text-muted d-block">{{ $row['late_rate'] }}%</small>
                                            </td>
                                            <td>
                                                @if ($row['average_minutes'] === null)
                                                    <span class="text-muted">—</span>
                                                @else
                                                    {{ $row['average_minutes'] }} {{ __('min') }}
                                                @endif
                                            </td>
                                            <td class="text-end">{{ moneyFormat($row['earnings']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="text-center">{{ __('No data found') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ __('Why journeys failed') }}</h6></div>
                    <div class="card-body">
                        @forelse ($failures as $row)
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span>{{ $row['reason'] }}</span>
                                <span>
                                    <strong>{{ $row['count'] }}</strong>
                                    <small class="text-muted">{{ $row['share'] }}%</small>
                                </span>
                            </div>
                        @empty
                            <p class="text-muted mb-0">{{ __('No failed journeys in this period.') }}</p>
                        @endforelse

                        {{-- The reasons want different responses. --}}
                        <p class="text-muted small mb-0 mt-3">
                            {{ __('A wrong address is a data problem, an unavailable customer is a scheduling one, and a piece count mismatch is neither.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
