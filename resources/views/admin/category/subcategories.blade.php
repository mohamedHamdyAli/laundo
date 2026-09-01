@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Sub Categories') }}</h5>

        <a href="{{ route('admin.category.index') }}" class="btn btn-primary">
            <i class="fa fa-arrow-left"></i> {{ __('Back to All Categories') }}
        </a>
    </div>
    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>
                        <div class="table-responsive hoverable-table">
                            @if ($subcategories->count() > 0)
                                <table class="table table-bordered table-hover text-md-nowrap align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ __('Image') }}</th>
                                            <th>{{ __('ID') }}</th>
                                            <th>{{ __('Name') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th>{{ __('Created At') }}</th>
                                            <th class="text-center">{{ __('Action') }}</th>

                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($subcategories as $subcategory)
                                            <tr>
                                                <td>{!! getImageDashboardUrl($subcategory->image) !!}</td>
                                                <td>{{ $subcategory->id }}</td>
                                                <td>
                                                    {{-- START: Modified Code --}}
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>{{ getLocalizedValueDashboard($subcategory, 'name') ?? '-' }}</span>
                                                        {{-- Check if this subcategory has children --}}
                                                        @if ($subcategory->children && $subcategory->children->count() > 0)
                                                            <a href="{{ route('admin.category.showSubCategories', $subcategory->id) }}"
                                                                class="btn btn-primary rounded-circle d-inline-flex justify-content-center align-items-center"
                                                                style="width: 30px; height: 30px;"
                                                                title="View Subcategories">
                                                                <i class="fa fa-plus text-white small"></i>
                                                            </a>
                                                        @endif
                                                    </div>
                                                    {{-- END: Modified Code --}}
                                                </td>
                                                <td>
                                                    <x-status-toggle-button :id="$subcategory->id" :status="$subcategory->status"
                                                        endpoint="{{ route('admin.category.toggleStatus', $subcategory->id) }}" />
                                                </td>
                                                <td>{{ humanDate($subcategory->created_at, 'Y-m-d H:i') }}
                                                </td>
                                                <td class="text-center">
                                                    @include('admin.category.shared.controlBut', [
                                                        'row' => $subcategory,
                                                    ])
                                                </td>
                                            </tr>
                                        @endforeach

                                    </tbody>
                                </table>

                                <div class="d-flex justify-content-center mt-4">
                                    {{ $subcategories->links() }}
                                </div>
                            @else
                                <div class="alert alert-warning text-center">
                                    {{ __('No subcategories found for') }} <strong>{{ $parentCategory->name }}</strong>.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
