{{--
    A bar per day, drawn with divs.

    No chart library: the vendor template ships several and none is wired up, and
    a dependency for a proportional bar is a dependency to maintain forever. The
    empty days are already zeroes by the time they get here — the report fills
    them — so a quiet Tuesday shows as a gap rather than being drawn over.

    The bar is positioned absolutely inside a track rather than given a
    percentage height directly. It used to be a `height: N%` div inside a
    content-sized wrapper: a percentage height needs a parent with a definite
    height to resolve against, and there wasn't one — so every bar on all five
    report screens collapsed to its 4px minimum and the chart had never drawn
    anything but a row of dashes. The track gets its height from `flex: 1`
    inside a fixed-height column, which is definite, and the bar is absolutely
    positioned against it.
--}}
@php
    $peak = collect($series)->max($valueKey) ?: 1;
@endphp

<div class="chart-bars">
    @foreach ($series as $point)
        @php
            $value = $point[$valueKey];
            // A day with one order must still draw something, or a quiet day and
            // an empty day look identical.
            $height = $value > 0 ? max(($value / $peak) * 100, 3) : 0;
        @endphp
        <div class="chart-col" title="{{ $point['date'] }} — {{ $value }}">
            <div class="chart-track">
                @if ($value > 0)
                    <div class="chart-bar" style="height: {{ $height }}%"></div>
                @endif
            </div>
            <span class="chart-label">
                {{ \Illuminate\Support\Carbon::parse($point['date'])->format('d/m') }}
            </span>
        </div>
    @endforeach
</div>
