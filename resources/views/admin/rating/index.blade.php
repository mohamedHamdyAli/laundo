@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Ratings') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Overall') }}</h6>
                    @if ($summary['average'] === null)
                        {{-- No ratings and a bad average are different claims. --}}
                        <h3 class="mb-0 text-muted">{{ __('Not rated yet') }}</h3>
                    @else
                        <h3 class="mb-0 {{ $summary['average'] < 3.5 ? 'text-attention' : '' }}">
                            {{ $summary['average'] }} / 5
                        </h3>
                        <small class="text-muted">
                            {{ $summary['total'] }} {{ __('ratings') }}
                        </small>
                    @endif
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Unhappy') }}</h6>
                    <h3 class="mb-0 {{ $summary['poor'] > 0 ? 'text-danger' : '' }}">{{ $summary['poor'] }}</h3>
                    <small class="text-muted">{{ __('Two stars or fewer') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('With a comment') }}</h6>
                    <h3 class="mb-0">{{ $summary['commented'] }}</h3>
                    {{-- The design's own placeholder is «اكتب ملاحظاتك أو شكواك»,
                         so these are the ones somebody can actually act on. --}}
                    <small class="text-muted">{{ __('Something to reply to') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('By aspect') }}</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            @foreach ([
                                'service_quality' => __('Service quality'),
                                'delivery' => __('Pickup and delivery'),
                                'timing' => __('Timing'),
                            ] as $key => $label)
                                <tr>
                                    <td class="p-0 small">{{ $label }}</td>
                                    <td class="p-0 text-end small">
                                        @if ($summary['aspects'][$key] === null)
                                            <span class="text-muted">—</span>
                                        @else
                                            <strong>{{ $summary['aspects'][$key] }}</strong>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div></div>
            </div>
        </div>

        @php $topTags = collect($tagCounts)->filter(fn ($t) => $t['count'] > 0); @endphp
        @if ($topTags->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">{{ __('What customers liked') }}</h6>
                    {{-- The reason the chips are a closed set: free text could not
                         be counted, and this tally is the only use they have. --}}
                    <small class="text-muted">{{ __('How often each was picked') }}</small>
                </div>
                <div class="card-body d-flex flex-wrap gap-2">
                    @foreach ($topTags as $entry)
                        <span class="badge bg-light">
                            {{ __($entry['tag']->label()) }}
                            <strong class="ms-1">{{ $entry['count'] }}</strong>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="ratingBandFilter" class="form-select" style="max-width: 220px;">
                        <option value="all" @selected($band === 'all')>{{ __('All') }}</option>
                        <option value="poor" @selected($band === 'poor')>{{ __('Unhappy') }}</option>
                        <option value="commented" @selected($band === 'commented')>{{ __('With a comment') }}</option>
                        <option value="good" @selected($band === 'good')>{{ __('Happy') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 340px;">
                        <input type="text" id="ratingSearchInput" class="form-control"
                            placeholder="{{ __('Search by order, customer or comment...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-borderless table-striped" id="table_list">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Overall') }}</th>
                                <th>{{ __('By aspect') }}</th>
                                <th>{{ __('What customers liked') }}</th>
                                <th>{{ __('Comment') }}</th>
                                <th>{{ __('Rated') }}</th>
                            </tr>
                        </thead>
                        <tbody id="rating-table-body">
                            @include('admin.rating.partials._rating_table_body', ['ratings' => $ratings])
                        </tbody>
                    </table>
                </div>

                <div id="pagination-wrapper">
                    {{ $ratings->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#ratingSearchInput',
                tableBodySelector: '#rating-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.rating.search') }}",
                colspan: 7
            });

            $('#ratingBandFilter').on('change', function () {
                window.location = "{{ route('admin.rating.index') }}?band=" + $(this).val();
            });
        });
    </script>
@endpush
