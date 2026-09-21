@extends('layouts.main')

{{-- One submission, field by field.

     The whole reason this screen exists rather than an approve button on a list:
     approving a payload you cannot read is not reviewing it. Every field the
     driver sent is shown against what the record says now, so the person
     deciding can see what would change before they change it. --}}

@php
    use App\Modules\Driver\Models\DriverRecordSubmission;
@endphp

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Document Review') }}
            <small class="text-muted">— {{ $row->driver?->name }}</small>
        </h5>
        <a href="{{ route('admin.driver_record_submission.index') }}" class="btn btn-sm btn-outline-secondary">
            {{ __('Back') }}
        </a>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="fw-bold">{{ $row->driver?->name }}</div>
                                <div class="text-muted small" dir="ltr">{{ $row->driver?->phone }}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge {{ $row->isPending() ? 'text-bg-warning' : ($row->status === DriverRecordSubmission::APPROVED ? 'text-bg-success' : 'text-bg-secondary') }}">
                                    {{ __($row->statusLabel()) }}
                                </span>
                                <div class="text-muted small mt-1">{{ humanDate($row->created_at) }}</div>
                            </div>
                        </div>

                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>{{ __('Field') }}</th>
                                    <th>{{ __('Now') }}</th>
                                    <th>{{ __('Proposed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($diff as $line)
                                    <tr class="{{ $line['changed'] ? '' : 'text-muted' }}">
                                        <td>{{ __(Str::headline($line['field'])) }}</td>

                                        <td>
                                            @if ($line['type'] === 'image')
                                                @if ($line['current'])
                                                    <a href="{{ getImageassetUrl($line['current']) }}" target="_blank">
                                                        {{ __('View current') }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">{{ __('Not uploaded') }}</span>
                                                @endif
                                            @else
                                                {{ $line['current'] ?: '—' }}
                                            @endif
                                        </td>

                                        <td>
                                            @if ($line['type'] === 'image')
                                                @if ($line['proposed'])
                                                    {{-- Opened rather than shown inline: these are
                                                         photographs of documents, and a thumbnail is
                                                         exactly the size at which a licence number
                                                         cannot be read. --}}
                                                    <a href="{{ getImageassetUrl($line['proposed']) }}" target="_blank"
                                                        class="fw-bold">{{ __('Open what they sent') }}</a>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            @else
                                                <strong>{{ $line['proposed'] ?: '—' }}</strong>
                                            @endif

                                            @unless ($line['changed'])
                                                <span class="badge text-bg-light ms-1">{{ __('Unchanged') }}</span>
                                            @endunless
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted">{{ __('Nothing was submitted.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        @if ($row->note)
                            <div class="alert alert-secondary mb-0">
                                <strong>{{ __('Note') }}:</strong> {{ $row->note }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        @if (! $row->isPending())
                            <p class="text-muted mb-0">
                                {{ __('Decided by :name on :date', [
                                    'name' => $row->reviewer?->name ?? __('somebody'),
                                    'date' => humanDate($row->reviewed_at),
                                ]) }}
                            </p>
                        @elseif (canDo('driver_record_submission.update'))
                            {{-- Two separate forms, and neither carries
                                 `needs-validation`: there is nothing typed in the
                                 approve one to lose, and a background submit
                                 would only hide the page it leads to. --}}
                            <form method="POST" action="{{ route('admin.driver_record_submission.approve', $row->id) }}"
                                class="mb-4">
                                @csrf
                                <button type="submit" class="btn btn-success w-100">
                                    {{ __('Approve and apply') }}
                                </button>
                                <small class="text-muted d-block mt-2">
                                    {{ __('Only the fields above are written to the driver. Anything they did not send is left alone.') }}
                                </small>
                            </form>

                            <form method="POST" action="{{ route('admin.driver_record_submission.reject', $row->id) }}">
                                @csrf
                                <label class="form-label">
                                    {{ __('Why not?') }} <span class="text-danger">*</span>
                                </label>
                                <textarea name="note" rows="3" class="form-control @error('note') is-invalid @enderror"
                                    placeholder="{{ __('The driver sees this.') }}">{{ old('note') }}</textarea>
                                @error('note')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <button type="submit" class="btn btn-outline-danger w-100 mt-2">
                                    {{ __('Reject') }}
                                </button>
                                <small class="text-muted d-block mt-2">
                                    {{ __('A driver told only «rejected» sends the same photograph again.') }}
                                </small>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
