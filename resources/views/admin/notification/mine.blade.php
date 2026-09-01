@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('My Notifications') }}</h5>
        @if ($unread > 0)
            <form method="POST" action="{{ route('admin.myNotifications.read-all') }}">
                @csrf
                <button class="btn btn-sm btn-outline-secondary">
                    {{ __('Mark all as read') }} ({{ $unread }})
                </button>
            </form>
        @endif
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                {{--
                    This is the operator's inbox — Laravel's `notifications` table,
                    which `NotificationDispatcher::toDatabase()` writes to and the
                    topbar bell reads. It is NOT the notification log, which records
                    every delivery attempt to customers and drivers.

                    It exists because the bell is a ten-item dropdown, and an alert
                    that scrolled past the tenth was gone — a poor fate for the only
                    warning that a task has had no driver for six hours.
                --}}
                @forelse ($notifications as $notification)
                    @php
                        $data = $notification->data ?? [];
                        $isUnread = $notification->read_at === null;
                    @endphp
                    <div class="d-flex align-items-start gap-3 py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <div class="pt-1">
                            @if ($isUnread)
                                <span class="badge rounded-pill bg-primary">&nbsp;</span>
                            @else
                                <span class="badge rounded-pill bg-light">&nbsp;</span>
                            @endif
                        </div>

                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <strong class="{{ $isUnread ? '' : 'text-muted' }}">
                                    {{ $data['title'] ?? __('Notification') }}
                                </strong>
                                <small class="text-muted text-nowrap">
                                    {{ $notification->created_at?->diffForHumans() }}
                                </small>
                            </div>

                            <div class="{{ $isUnread ? '' : 'text-muted' }}">
                                {{ $data['message'] ?? '' }}
                            </div>

                            <div class="mt-1 d-flex gap-2 align-items-center">
                                @if (filled($data['url'] ?? null))
                                    <a href="{{ $data['url'] }}" class="btn btn-sm btn-outline-primary">
                                        {{ __('Open') }}
                                    </a>
                                @endif

                                @if ($isUnread)
                                    <form method="POST"
                                        action="{{ route('admin.myNotifications.read', $notification->id) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-link p-0">{{ __('Mark as read') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">{{ __('No notifications') }}</div>
                @endforelse

                <div class="mt-3">{{ $notifications->links() }}</div>
            </div>
        </div>
    </section>
@endsection
