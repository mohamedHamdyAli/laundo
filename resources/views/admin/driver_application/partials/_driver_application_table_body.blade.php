@forelse ($applications as $application)
    {{-- Waiting rows carry the warn tone, the same way an offer that is going
         nowhere does: the whole list exists to show who nobody has rung. --}}
    <div class="stack-row {{ $application->isWaiting() ? 'tone-warn' : '' }}">
        <div>
            <span class="row-lead">
                @if (canDo('driver_application.view'))
                    <a href="{{ route('admin.driver_application.show', $application->id) }}">
                        {{ $application->name }}
                    </a>
                @else
                    {{ $application->name }}
                @endif
            </span>
        </div>

        {{-- One tap, not something to copy out by hand: the only thing anybody
             does on this screen is ring the number. --}}
        <div>
            <a href="tel:{{ $application->phone }}" dir="ltr">{{ $application->phone }}</a>
        </div>

        <div>
            <span class="text-muted">
                {{ $application->note ? Str::limit($application->note, 60) : '—' }}
            </span>
        </div>

        <div>
            <span class="text-muted">{{ humanDate($application->created_at) }}</span>
        </div>

        <div>
            @if ($application->isWaiting())
                <span class="badge bg-warning text-dark">{{ __('Waiting') }}</span>
            @else
                <span class="badge bg-success">{{ __('Handled') }}</span>
                @if ($application->handler)
                    <span class="d-block text-muted small mt-1">{{ $application->handler->name }}</span>
                @endif
            @endif
        </div>

        <div class="text-end">
            @if (canDo('driver_application.toggle'))
                <form method="POST" action="{{ route('admin.driver_application.handled', $application->id) }}"
                    class="d-inline">
                    @csrf
                    <button type="submit"
                        class="btn btn-sm {{ $application->isWaiting() ? 'btn-success' : 'btn-outline-secondary' }}">
                        {{ $application->isWaiting() ? __('We called them') : __('Put back') }}
                    </button>
                </form>
            @endif

            @if (canDo('driver_application.delete'))
                <form method="POST" action="{{ route('admin.driver_application.delete', $application->id) }}"
                    class="d-inline" onsubmit="return confirm('{{ __('Delete this application?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="stack-empty">
        {{ __('Nobody has applied yet.') }}
    </div>
@endforelse
