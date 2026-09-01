{{--
    A bar per day, drawn with divs.

    No chart library: the vendor template ships several and none is wired up, and
    a dependency for a proportional bar is a dependency to maintain forever. The
    empty days are already zeroes by the time they get here — the report fills
    them — so a quiet Tuesday shows as a gap rather than being drawn over.
--}}
@php
    $peak = collect($series)->max($valueKey) ?: 1;
@endphp

<div class="d-flex align-items-end gap-1" style="height: 160px; overflow-x: auto;">
    @foreach ($series as $point)
        @php $height = max(($point[$valueKey] / $peak) * 100, $point[$valueKey] > 0 ? 4 : 0); @endphp
        <div class="text-center" style="min-width: 26px; flex: 1;"
            title="{{ $point['date'] }} — {{ $point[$valueKey] }}">
            <div class="bg-primary rounded-top mx-auto"
                style="height: {{ $height }}%; width: 60%; min-height: {{ $point[$valueKey] > 0 ? '4px' : '0' }};"></div>
            <small class="text-muted d-block" style="font-size: 9px;">
                {{ \Illuminate\Support\Carbon::parse($point['date'])->format('d/m') }}
            </small>
        </div>
    @endforeach
</div>
