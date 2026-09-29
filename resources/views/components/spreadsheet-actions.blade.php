@props([
    // The sheet key — the file in app/Support/Spreadsheet/Sheets.
    'sheet',
    // The list's search box, so the export holds what the list shows.
    'search' => null,
    // Filter controls the screen has: ['status' => '#statusFilter', ...].
    'filters' => [],
])

@php
    $definition = app(\App\Support\Spreadsheet\SheetRegistry::class)->find($sheet);
    $mayExport = $definition && canDo($definition->permission().'.view');
    $mayImport = $definition
        && $definition->importable()
        && \App\Support\LaundryContext::currentId() === null
        && (canDo($definition->createPermission()) || canDo($definition->permission().'.update'));
    $report = session('spreadsheet_report');
    $modalId = 'spreadsheet-import-'.$sheet;
@endphp

@if ($mayExport || $mayImport)
    <div class="d-inline-flex gap-2 align-items-center">
        @if ($mayExport)
            {{-- The URL is finished on click, from whatever the screen is showing
                 at that moment — a search typed after the page loaded counts. --}}
            <a href="{{ route('admin.spreadsheet.export', $sheet) }}" class="btn btn-sm btn-outline-success js-spreadsheet-export"
                data-search="{{ $search }}" data-filters='@json($filters)'>
                <i class="bi bi-file-earmark-excel me-1"></i>{{ __('Export Excel') }}
            </a>
        @endif

        @if ($mayImport)
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
                <i class="bi bi-upload me-1"></i>{{ __('Import Excel') }}
            </button>
        @endif
    </div>

    @if ($mayImport)
        <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                {{-- A plain form, not `needs-validation`: a file cannot survive a
                     background submit's round trip any better than a reload. --}}
                <form method="POST" action="{{ route('admin.spreadsheet.import', $sheet) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Import Excel') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted">
                                {{ __('Start from an export or from the template. A row with an id changes that record; a row without one is added. Nothing is ever deleted, and a blank cell leaves the field as it was.') }}
                            </p>
                            <p class="small text-muted">
                                {{ __('Each row is checked like the add form. Good rows are saved and the rest are listed with their row number, so you can fix them and import again. Up to :max rows at a time.', ['max' => \App\Support\Spreadsheet\Importer::MAX_ROWS]) }}
                            </p>
                            <input type="file" name="file" class="form-control" accept=".xlsx" required>
                            <a href="{{ route('admin.spreadsheet.template', $sheet) }}" class="small d-inline-block mt-2">
                                <i class="bi bi-download me-1"></i>{{ __('Download a blank template') }}
                            </a>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Import') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if (is_array($report) && ($report['sheet'] ?? null) === $sheet)
        {{-- What the import did, on the screen it was done from. The refused
             rows are listed in full: the row number and the form's own words
             are everything somebody needs to fix the file. --}}
        <div class="alert {{ $report['failed'] ? 'alert-warning' : 'alert-success' }} mt-3 w-100 small">
            <strong>{{ __('Import finished.') }}</strong>
            {{ __(':created added, :updated changed, :failed refused.', [
                'created' => $report['created'], 'updated' => $report['updated'], 'failed' => count($report['failed']),
            ]) }}
            @if ($report['skipped'] > 0)
                {{ __(':count rows past the limit were not read.', ['count' => $report['skipped']]) }}
            @endif

            @if ($report['failed'])
                <ul class="mb-0 mt-2">
                    @foreach ($report['failed'] as $failure)
                        <li>{{ __('Row :row', ['row' => $failure['row']]) }}: {{ implode(' ', $failure['messages']) }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @once
        @push('scripts')
            <script>
                // Delegated: several list screens redraw their toolbar area.
                $(document).on('click', '.js-spreadsheet-export', function (event) {
                    const $link = $(this);
                    const params = new URLSearchParams();
                    const search = $link.data('search');
                    const filters = $link.data('filters') || {};

                    if (search && $(search).length && $(search).val()) {
                        params.set('query', $(search).val());
                    }

                    Object.keys(filters).forEach(function (name) {
                        const value = $(filters[name]).val();
                        if (value !== undefined && value !== null && value !== '') {
                            params.set(name, value);
                        }
                    });

                    const query = params.toString();
                    if (query) {
                        event.preventDefault();
                        window.location = $link.attr('href') + '?' + query;
                    }
                });
            </script>
        @endpush
    @endonce
@endif
