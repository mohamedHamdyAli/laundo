@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Laundry Performance') }}</h5>
        <span class="text-muted small">{{ $range->label() }}</span>
    </div>

    <section class="section">
        <div class="card mb-3">
            <div class="card-body">
                @include('admin.report.partials._range', ['exportable' => 'laundries'])
                <p class="text-muted small mb-0">
                    {{-- Nothing ever stored turnaround; the status log has recorded
                         every move since P6 and this is the first thing to add it
                         up. --}}
                    {{ __('Turnaround is measured from collection to ready for delivery — the span the laundry controls, excluding the driving at either end and the customer\'s own thinking time.') }}
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Laundry') }}</th>
                                <th>{{ __('Orders') }}</th>
                                <th>{{ __('Completed') }}</th>
                                <th>{{ __('Cancelled') }}</th>
                                <th>{{ __('Disputed') }}</th>
                                <th>{{ __('Avg review rounds') }}</th>
                                <th>{{ __('Avg turnaround') }}</th>
                                <th>{{ __('Rating') }}</th>
                                <th class="text-end">{{ __('Revenue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td><strong>{{ $row['laundry'] }}</strong></td>
                                    <td>{{ $row['orders'] }}</td>
                                    <td class="text-success">{{ $row['completed'] }}</td>
                                    <td class="{{ $row['cancelled'] > 0 ? 'text-danger' : '' }}">
                                        {{ $row['cancelled'] }}
                                    </td>
                                    <td class="{{ $row['disputed'] > 0 ? 'text-attention' : '' }}">
                                        {{ $row['disputed'] }}
                                        @if ($row['disputed'] > 0)
                                            <small class="text-muted d-block">
                                                {{ __('customer questioned the count') }}
                                            </small>
                                        @endif
                                    </td>
                                    <td>{{ $row['average_review_rounds'] }}</td>
                                    <td>
                                        @if ($row['average_turnaround_hours'] === null)
                                            {{-- Not zero: an unmeasured turnaround is
                                                 not an instant one. --}}
                                            <span class="text-muted">{{ __('Not measured yet') }}</span>
                                        @else
                                            {{ $row['average_turnaround_hours'] }} {{ __('h') }}
                                            <small class="text-muted d-block">
                                                {{ $row['measured_orders'] }} {{ __('measured') }}
                                            </small>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['average_rating'] === null)
                                            {{-- Unrated and badly rated are different
                                                 claims; a laundry nobody has rated yet
                                                 must not read as the worst one. --}}
                                            <span class="text-muted">{{ __('Not rated yet') }}</span>
                                        @else
                                            <strong class="{{ $row['average_rating'] < 3.5 ? 'text-attention' : '' }}">
                                                {{ $row['average_rating'] }}
                                            </strong><span class="text-muted">/5</span>
                                            <small class="text-muted d-block">
                                                {{ $row['ratings'] }} {{ __('ratings') }}
                                                @if ($row['unhappy'] > 0)
                                                    · <span class="text-danger">
                                                        {{ $row['unhappy'] }} {{ __('unhappy') }}
                                                    </span>
                                                @endif
                                            </small>
                                        @endif
                                    </td>
                                    <td class="text-end"><strong>{{ moneyFormat($row['revenue']) }}</strong></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center">{{ __('No data found') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection
