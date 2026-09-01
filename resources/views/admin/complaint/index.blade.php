@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Complaints') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('New') }}</h6>
                    <h3 class="mb-0 {{ $counts['new'] > 0 ? 'text-danger' : '' }}">{{ $counts['new'] }}</h3>
                    <small class="text-muted">{{ __('Nobody has picked these up') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Being handled') }}</h6>
                    <h3 class="mb-0">{{ $counts['in_progress'] }}</h3>
                    <small class="text-muted">{{ __('Somebody has them') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Waiting over a day') }}</h6>
                    <h3 class="mb-0 {{ $counts['stale'] > 0 ? 'text-danger' : '' }}">{{ $counts['stale'] }}</h3>
                    {{-- A total never says the queue is not being worked. This does. --}}
                    <small class="text-muted">{{ __('Still open after 24 hours') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('From ratings') }}</h6>
                    <h3 class="mb-0 {{ $counts['from_ratings'] > 0 ? 'text-attention' : '' }}">
                        {{ $counts['from_ratings'] }}
                    </h3>
                    {{-- The rating form's own placeholder is «اكتب ملاحظاتك أو
                         شكواك», so these are complaints that arrived elsewhere. --}}
                    <small class="text-muted">{{ __('Low ratings with a comment') }}</small>
                </div></div>
            </div>
        </div>

        @php $topCategories = collect($byCategory)->filter(fn ($c) => $c['count'] > 0); @endphp
        @if ($topCategories->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">{{ __('What people complain about') }}</h6>
                    <small class="text-muted">{{ __('Most common first') }}</small>
                </div>
                <div class="card-body d-flex flex-wrap gap-2">
                    @foreach ($topCategories as $entry)
                        <span class="badge bg-light">
                            {{ __($entry['category']->label()) }}
                            <strong class="ms-1">{{ $entry['count'] }}</strong>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="complaintStatusFilter" class="form-select" style="max-width: 240px;">
                        <option value="open" @selected($status === 'open')>{{ __('Open') }}</option>
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($status === $case->value)>
                                {{ __($case->label()) }}
                            </option>
                        @endforeach
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 340px;">
                        <input type="text" id="complaintSearchInput" class="form-control"
                            placeholder="{{ __('Search by reference, order, customer or text...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-borderless table-striped" id="table_list">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Reference') }}</th>
                                <th>{{ __('From') }}</th>
                                <th>{{ __('About') }}</th>
                                <th>{{ __('What they said') }}</th>
                                <th>{{ __('Laundry') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Waiting') }}</th>
                                <th class="text-center">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody id="complaint-table-body">
                            @include('admin.complaint.partials._complaint_table_body', [
                                'complaints' => $complaints,
                                'statuses' => $statuses,
                            ])
                        </tbody>
                    </table>
                </div>

                <div id="pagination-wrapper">
                    {{ $complaints->withQueryString()->links() }}
                </div>
            </div>
        </div>

        @if ($ratingComplaints->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">{{ __('Complaints that arrived as ratings') }}</h6>
                    {{-- A separate table, not merged into the paginator above:
                         these live in another table with different actions, and a
                         "resolve" button here would have nothing to resolve. --}}
                    <small class="text-muted">
                        {{ __('Two stars or fewer, with something written') }}
                    </small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-borderless table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Order') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Overall') }}</th>
                                    <th>{{ __('Laundry') }}</th>
                                    <th>{{ __('Comment') }}</th>
                                    <th>{{ __('Rated') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ratingComplaints as $rating)
                                    <tr>
                                        <td>
                                            @if ($rating->order)
                                                <a href="{{ route('admin.order.show', $rating->order->id) }}">
                                                    {{ $rating->order->code }}
                                                </a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            {{ $rating->customer?->name ?? '—' }}
                                            <small class="text-muted d-block">{{ $rating->customer?->phone }}</small>
                                        </td>
                                        <td>
                                            <strong class="text-danger">{{ $rating->overall }}</strong>
                                            <span class="text-muted">/5</span>
                                        </td>
                                        <td>
                                            @if ($rating->laundry)
                                                {{ getLocalizedValueDashboard($rating->laundry, 'name') }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td style="max-width: 340px;">{{ $rating->comment }}</td>
                                        <td>
                                            <small class="text-muted">
                                                {{ $rating->created_at?->diffForHumans() }}
                                            </small>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2">
                        <a href="{{ route('admin.rating.index') }}?band=poor" class="btn btn-sm btn-outline-secondary">
                            {{ __('See all of them') }}
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#complaintSearchInput',
                tableBodySelector: '#complaint-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.complaint.search') }}",
                colspan: 8
            });

            $('#complaintStatusFilter').on('change', function () {
                window.location = "{{ route('admin.complaint.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
