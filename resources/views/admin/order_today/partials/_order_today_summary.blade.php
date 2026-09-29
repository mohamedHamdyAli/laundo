{{--
    The totals, summed over exactly the rows under them — redrawn with the list
    by OrderTodayController@search, so the two can never disagree.

    By service first, then every piece across all of them: a pair of trousers to
    wash and iron and a pair to iron only are different jobs on the floor, but
    «how many trousers today» is still a question somebody asks.
--}}
<div class="row g-3 mb-3">
    <div class="col-sm-6">
        <div class="card h-100 stat-card mb-0">
            <div class="card-body">
                <h6 class="text-muted mb-1">{{ __('Orders') }}</h6>
                <h3 class="mb-0" data-summary="orders">{{ $summary['orders'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="card h-100 stat-card mb-0">
            <div class="card-body">
                <h6 class="text-muted mb-1">{{ __('Pieces') }}</h6>
                <h3 class="mb-0" data-summary="pieces">{{ $summary['pieces'] }}</h3>
                @if ($summary['estimated'] > 0)
                    {{-- A total built partly on the customer's own estimate is not
                         a count, and says so. --}}
                    <small class="text-muted">
                        {{ trans_choice(':count order not counted yet — its pieces are the customer\'s estimate|:count orders not counted yet — their pieces are the customer\'s estimate', $summary['estimated'], ['count' => $summary['estimated']]) }}
                    </small>
                @endif
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Pieces by item') }}</h6>
                @if ($summary['items_table'] === [])
                    <span class="text-muted small">{{ __('No pieces') }}</span>
                @else
                    {{-- One row per item, the count large enough to read across
                         the room: «10 بنطلون، 10 قميص، 2 ستارة». A column per
                         service only when there is more than one — a column
                         repeating the total says nothing. --}}
                    @php $perService = count($summary['service_columns']) > 1; @endphp
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 order-today-items">
                            <thead>
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    @if ($perService)
                                        @foreach ($summary['service_columns'] as $serviceName)
                                            <th class="text-center">{{ $serviceName }}</th>
                                        @endforeach
                                    @endif
                                    <th class="text-center">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary['items_table'] as $line)
                                    <tr>
                                        <td class="fw-semibold">{{ $line['item'] }}</td>
                                        @if ($perService)
                                            @foreach ($line['by_service'] as $qty)
                                                <td class="text-center">{{ $qty ?: '—' }}</td>
                                            @endforeach
                                        @endif
                                        <td class="text-center order-today-count">{{ $line['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>{{ __('All pieces') }}</th>
                                    @if ($perService)
                                        @foreach ($summary['service_totals'] as $serviceTotal)
                                            <th class="text-center">{{ $serviceTotal }}</th>
                                        @endforeach
                                    @endif
                                    <th class="text-center order-today-count">{{ $summary['pieces'] }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
