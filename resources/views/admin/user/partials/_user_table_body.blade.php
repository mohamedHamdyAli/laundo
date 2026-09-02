@forelse ($users as $user)
    {{-- An inactive record gets the stripe; an active one is the normal case
         and does not need flagging. --}}
    <div class="stack-row {{ $user->status === 'active' ? '' : 'tone-bad' }}">
        <div>
            <span class="row-thumb">{!! getImageDashboardUrl($user->image_profile) !!}</span>
        </div>
        <div>
            <span class="row-lead">
                @if (canDo('user.view'))
                    <a href="{{ route('admin.user.show', $user->id) }}">{{ $user->name ?? '-' }}</a>
                @else
                    {{ $user->name ?? '-' }}
                @endif
            </span>
            <span class="row-sub">#{{ $user->id }}</span>
        </div>
        <div>
            <span class="row-main">{{ $user->phone ?? '-' }}</span>
            {{-- «مرجع العميل» — the number printed on this customer's bags. It is
                 how the laundry matches a parcel whose label is torn, so it is
                 worth more on this screen than the raw row id. --}}
            <span class="row-sub">
                {{ $user->customer_reference ? __('Ref') . ' ' . $user->customer_reference : __('No reference') }}
            </span>
        </div>
        <div>
            <x-status-toggle-button :id="$user->id" :status="$user->status"
                endpoint="{{ route('admin.user.toggleStatus', $user->id) }}" permission="user.toggle" />
        </div>
        <div>
            <span class="row-sub">{{ humanDate($user->created_at, 'Y-m-d H:i') }}</span>
        </div>
        <div class="stack-actions">
            @include('admin.user.shared.controlBut', ['row' => $user])
        </div>
    </div>
@empty
    <div class="stack-empty">{{ __('No data found') }}</div>
@endforelse
