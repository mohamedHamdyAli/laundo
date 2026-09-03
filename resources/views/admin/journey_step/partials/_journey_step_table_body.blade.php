@forelse ($journeySteps as $index => $step)
    <div class="stack-row {{ $step->status !== 'active' ? 'tone-bad' : '' }}">
        <div>
            {{-- The number the app draws beside the card. It is the position in
                 this list, not a column — so it counts from the paginator's
                 offset rather than from `sort_order`, which may have gaps. --}}
            <span class="row-lead">{{ $journeySteps->firstItem() + $index }}</span>
        </div>
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($step->image) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('journey_step.view'))
                    <a href="{{ route('admin.journey_step.show', $step->id) }}">{{ getLocalizedValueDashboard($step, 'title') ?: '—' }}</a>
                @else
                    {{ getLocalizedValueDashboard($step, 'title') ?: '—' }}
                @endif
            </span>
            <span class="row-sub">{{ \Illuminate\Support\Str::limit(getLocalizedValueDashboard($step, 'description') ?: '—', 90) }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$step->id" :status="$step->status"
                endpoint="{{ route('admin.journey_step.toggleStatus', $step->id) }}" permission="journey_step.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.journey_step.shared.controlBut', ['row' => $step])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
