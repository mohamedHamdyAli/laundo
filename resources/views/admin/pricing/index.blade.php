@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Prices') }}</h5>
        <span class="text-muted small">
            {{ __('One price per item and service. Prices are global — laundries never set them.') }}
        </span>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        @include('layouts.validateMessage.errorMessage')

                        @if ($services->isEmpty())
                            {{-- No per-item service means no columns, so there is no grid to draw. --}}
                            <div class="alert alert-warning mb-0">
                                {{ __('No per-item services yet.') }}
                                @if (canDo('service.create'))
                                    <a href="{{ route('admin.service.create') }}">{{ __('Add a service') }}</a>
                                @endif
                            </div>
                        @elseif ($itemCategories->isEmpty())
                            <div class="alert alert-warning mb-0">
                                {{ __('No items yet.') }}
                                @if (canDo('item.create'))
                                    <a href="{{ route('admin.item.create') }}">{{ __('Add an item') }}</a>
                                @endif
                            </div>
                        @else
                            <form action="{{ route('admin.pricing.update') }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0" id="price-grid">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="min-width: 220px;">{{ __('Item') }}</th>
                                                @foreach ($services as $service)
                                                    <th class="text-center" style="min-width: 130px;">
                                                        {{ getLocalizedValueDashboard($service, 'name') }}
                                                        @php $d = $service->durationLabel(); @endphp
                                                        @if ($d)
                                                            <div class="fw-normal text-muted small">
                                                                {{ $d }}
                                                                {{ __($service->duration_unit === 'day' ? 'days' : 'hours') }}
                                                            </div>
                                                        @endif
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($itemCategories as $category)
                                                @continue($category->items->isEmpty())

                                                <tr class="table-secondary">
                                                    <td colspan="{{ $services->count() + 1 }}" class="fw-semibold">
                                                        {{ getLocalizedValueDashboard($category, 'name') }}
                                                    </td>
                                                </tr>

                                                @foreach ($category->items as $item)
                                                    <tr>
                                                        <td>{{ getLocalizedValueDashboard($item, 'name') }}</td>
                                                        @foreach ($services as $service)
                                                            <td>
                                                                <input type="number" step="0.01" min="0"
                                                                    class="form-control form-control-sm text-center"
                                                                    name="prices[{{ $item->id }}][{{ $service->id }}]"
                                                                    value="{{ $prices[$item->id . '-' . $service->id] ?? '' }}"
                                                                    placeholder="—"
                                                                    {{ canDo('item_price.update') ? '' : 'readonly' }}>
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="form-text mt-2">
                                    {{ __('Leave a cell empty to mean this service is not offered for that item. An empty cell is not a price of zero.') }}
                                </div>

                                @if ($quotedServices->isNotEmpty())
                                    <div class="alert alert-info mt-3 mb-0">
                                        <strong>{{ __('Quoted services are not shown here:') }}</strong>
                                        {{ $quotedServices->map(fn ($s) => getLocalizedValueDashboard($s, 'name'))->implode('، ') }}
                                        — {{ __('they are priced after the pieces are inspected.') }}
                                    </div>
                                @endif

                                @if (canDo('item_price.update'))
                                    <button type="submit" class="btn btn-primary mt-3">{{ __('Save Prices') }}</button>
                                @endif
                            </form>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
