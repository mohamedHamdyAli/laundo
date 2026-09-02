@forelse ($moderators as $moderator)
    <div class="stack-row {{ $moderator->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($moderator->image_profile) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('moderator.view'))
                    <a href="{{ route('admin.moderator.show', $moderator->id) }}">{{ $moderator->name ?? '-' }}</a>
                @else
                    {{ $moderator->name ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $moderator->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ $moderator->phone ?? '-' }}</span>
        </div>
        <div>
            {{-- What this account is allowed to do is the whole point of the
                 record, so the role is stated as a pill rather than plain text. --}}
            <span class="status-pill tone-live">{{ $moderator->role->name ?? __('No role') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$moderator->id" :status="$moderator->status"
                endpoint="{{ route('admin.moderator.toggleStatus', $moderator->id) }}" permission="moderator.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.moderator.shared.controlBut', ['row' => $moderator])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
