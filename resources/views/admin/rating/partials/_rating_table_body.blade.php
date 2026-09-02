@forelse ($ratings as $row)
    @php
        // Two stars or fewer is a customer waiting for a reply, so the row says
        // so rather than leaving it to be spotted in a column of numbers.
        $poor = $row->overall <= \App\Modules\Order\Models\OrderRating::POOR_AT_OR_BELOW;
    @endphp
    <div class="stack-row {{ $poor ? 'tone-bad' : '' }}">
        <div>
            <span class="row-lead">
                @if ($row->order)
                    <a href="{{ route('admin.order.show', $row->order->id) }}">{{ $row->order->code }}</a>
                @else
                    —
                @endif
            </span>
            <span class="row-sub">
                {{ $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : '—' }}
            </span>
        </div>
        <div>
            <span class="row-main">{{ $row->customer?->name ?? '—' }}</span>
            <span class="row-sub">{{ $row->customer?->phone }}</span>
        </div>
        <div>
            <span class="row-main rating-stars">{{ str_repeat('★', $row->overall) }}{{ str_repeat('☆', 5 - $row->overall) }}</span>
            <span class="row-sub">{{ $row->overall }}/5</span>
        </div>
        <div>
            @if (! $row->hasAspectDetail())
                {{-- Skipped the detail. Not the same as scoring it low, so it must
                     not render as three dashes that look like zeroes. --}}
                <span class="row-sub">{{ __('Detail skipped') }}</span>
            @else
                <span class="row-sub">
                    {{ __('Service quality') }} {{ $row->service_quality ?? '—' }} ·
                    {{ __('Pickup and delivery') }} {{ $row->delivery ?? '—' }} ·
                    {{ __('Timing') }} {{ $row->timing ?? '—' }}
                </span>
            @endif
            @php $cases = $row->tagCases(); @endphp
            @if (count($cases))
                <span class="row-sub">{{ collect($cases)->map(fn ($t) => __($t->label()))->implode(' · ') }}</span>
            @endif
        </div>
        <div>
            <span class="row-sub">{{ filled($row->comment) ? \Illuminate\Support\Str::limit($row->comment, 110) : '—' }}</span>
        </div>
        <div>
            <span class="row-sub">{{ $row->created_at?->diffForHumans() }}</span>
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
