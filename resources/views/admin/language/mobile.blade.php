@extends('layouts.main')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    Edit Language File: <strong class="text-primary">{{ $language->name }}</strong>
                    ({{ $language->code }}_mobile.json)
                </h3>
                <div>
                    <a href="{{ route('admin.language.download', ['type' => 'mobile', 'code' => $language->code]) }}"
                        class="btn btn-sm btn-success me-2" download="{{ $language->code }}_mobile.json">
                        <i class="fa fa-download me-1"></i> Download JSON
                    </a>

                    <a href="{{ route('admin.language.index') }}" class="btn btn-sm btn-secondary">Back to Languages</a>
                </div>
            </div>
            <div class="card-body">

                @if (session('success'))
                    <div class="alert alert-success" role="alert">{{ session('success') }}</div>
                @endif

                <form action="{{ route('admin.language.mobile.update', $language->id) }}" method="POST">
                    @csrf

                    <div id="translations-container">
                        @forelse($translations as $key => $value)
                            <div class="row mb-2 translation-row align-items-center">
                                <div class="col-md-5 mb-2">
                                    <label class="form-label">Main Key</label>
                                    <input type="text" class="form-control" value="{{ $key }}" readonly
                                        title="The key cannot be modified from here">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Translation</label>
                                    <input type="text" name="translations[{{ $key }}]" class="form-control"
                                        value="{{ $value }}">
                                </div>
                                <div class="col-md-1"></div>
                            </div>
                        @empty
                            <p class="text-center text-muted">The language file is empty. Start by adding a new translation.
                            </p>
                        @endforelse
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <button type="button" id="add-row-btn" class="btn btn-primary">
                            <i class="fa fa-plus me-1"></i> Add New Translation
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fa fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(document).ready(function() {
            // When the "Add New Translation" button is clicked
            $('#add-row-btn').click(function() {
                const newRowHtml = `
            <div class="row mb-2 translation-row align-items-center">
                <div class="col-md-5">
                    <input type="text" name="new_key[]" class="form-control" placeholder="Enter the new key">
                </div>
                <div class="col-md-6">
                    <input type="text" name="new_value[]" class="form-control" placeholder="Enter the value">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-danger remove-row-btn">
                        <i class="fa fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
                $('#translations-container').append(newRowHtml);
            });

            // When the delete button is clicked for any row (using event delegation)
            $('#translations-container').on('click', '.remove-row-btn', function() {
                $(this).closest('.translation-row').remove();
            });
        });
    </script>
@endpush
