@forelse ($staff as $member)
    <div class="stack-row {{ $member->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($member->image_profile) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('laundry_staff.view'))
                    <a href="{{ route('admin.laundry_staff.show', $member->id) }}">{{ $member->name ?? '-' }}</a>
                @else
                    {{ $member->name ?? '-' }}
                @endif
            </span>
            <span class="row-sub">{{ $member->phone ?? '-' }}</span>
        </div>
        <div>
            {{-- Which laundry employs them, since the same name can appear under
                 two of them. --}}
            <span class="row-main">{{ $member->laundry ? getLocalizedValueDashboard($member->laundry, 'name') : '-' }}</span>
            <span class="row-sub">#{{ $member->id }}</span>
        </div>
        <div>
            <span class="status-pill tone-live">{{ $member->role->name ?? __('No role') }}</span>
        </div>
        <div>
            <x-status-toggle-button :id="$member->id" :status="$member->status"
                endpoint="{{ route('admin.laundry_staff.toggleStatus', $member->id) }}"
                permission="laundry_staff.toggle" />
        </div>
        <div class="stack-actions">
            @include('admin.laundry_staff.shared.controlBut', ['row' => $member])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
