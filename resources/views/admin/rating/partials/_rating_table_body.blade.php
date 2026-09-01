@forelse ($ratings as $row)
    {{-- Two stars or fewer is a customer waiting for a reply, so the row says so
         rather than leaving it to be spotted in a column of numbers. --}}
    <tr class="{{ $row->overall <= \App\Modules\Order\Models\OrderRating::POOR_AT_OR_BELOW ? 'table-danger' : '' }}">
        <td>
            @if ($row->order)
                <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
            @else
                <span class="text-muted">—</span>
            @endif
            @if ($row->laundry)
                <small class="text-muted d-block">
                    {{ getLocalizedValueDashboard($row->laundry, 'name') }}
                </small>
            @endif
        </td>
        <td>
            {{ $row->customer?->name ?? '—' }}
            <small class="text-muted d-block">{{ $row->customer?->phone }}</small>
        </td>
        <td>
            <strong>{{ $row->overall }}</strong><span class="text-muted">/5</span>
            <small class="d-block" style="color: #b45309; letter-spacing: 1px;">
                {{ str_repeat('★', $row->overall) }}{{ str_repeat('☆', 5 - $row->overall) }}
            </small>
        </td>
        <td>
            @if (! $row->hasAspectDetail())
                {{-- Skipped the detail. Not the same as scoring it low, so it must
                     not render as three dashes that look like zeroes. --}}
                <span class="text-muted small">{{ __('Detail skipped') }}</span>
            @else
                <small class="d-block">
                    {{ __('Service quality') }}:
                    <strong>{{ $row->service_quality ?? '—' }}</strong>
                </small>
                <small class="d-block">
                    {{ __('Pickup and delivery') }}:
                    <strong>{{ $row->delivery ?? '—' }}</strong>
                </small>
                <small class="d-block">
                    {{ __('Timing') }}: <strong>{{ $row->timing ?? '—' }}</strong>
                </small>
            @endif
        </td>
        <td>
            @php $cases = $row->tagCases(); @endphp
            @forelse ($cases as $tag)
                <span class="badge bg-light mb-1">{{ __($tag->label()) }}</span>
            @empty
                <span class="text-muted">—</span>
            @endforelse
        </td>
        <td style="max-width: 320px;">
            @if (filled($row->comment))
                {{ $row->comment }}
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
        <td>
            <small class="text-muted">{{ $row->created_at?->diffForHumans() }}</small>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="7" class="text-center">{{ __('No data found') }}</td>
    </tr>
@endforelse
