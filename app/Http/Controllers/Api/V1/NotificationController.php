<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The notification list, the device registry, and the design's «الإشعارات»
 * toggle.
 *
 * Isolation as everywhere else on this API: everything is reached through
 * `$request->user()`, so there is no id to guess at.
 */
class NotificationController extends Controller
{
    /**
     * The in-app list both apps show.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->notifications();

        if ($request->boolean('unread')) {
            $query = $user->unreadNotifications();
        }

        $rows = $query->paginate(min((int) $request->get('per_page', 20), 50));

        $payload = [];

        foreach ($rows->items() as $row) {
            $data = $row->data ?? [];

            $payload[] = [
                'id' => $row->id,
                'title' => $data['title'] ?? null,
                'body' => $data['message'] ?? null,
                'url' => $data['url'] ?? null,
                'event' => $data['meta']['event'] ?? null,
                'meta' => $data['meta'] ?? [],
                'read' => $row->read_at !== null,
                'at' => humanDate($row->created_at),
            ];
        }

        return successReturnPaginated($payload, $rows);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return successReturnData(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return failReturnNotFound(__('Notification not found.'));
        }

        $notification->markAsRead();

        return returnSuccessMsg(__('Marked as read.'));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return returnSuccessMsg(__('All notifications marked as read.'));
    }

    /**
     * Register this handset for push.
     *
     * `updateOrCreate` on the token, not on the user: FCM reissues a token to
     * whoever installs the app next on that handset, so a token arriving for a
     * different user has genuinely moved and must follow the new owner. Keyed the
     * other way round, two people would both believe they own it and one would
     * receive the other's order updates.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'app' => ['nullable', Rule::in(['customer', 'driver'])],
            'locale' => ['nullable', 'string', 'max:8'],
        ]);

        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'app' => $data['app'] ?? null,
                'locale' => $data['locale'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return successReturnData(['id' => $device->id], __('Device registered.'));
    }

    /**
     * Sign this handset out of push — what a logout should call.
     */
    public function forgetDevice(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->get('token'))
            ->delete();

        return returnSuccessMsg(__('Device removed.'));
    }

    /**
     * «الإشعارات» in the account screen.
     */
    public function preferences(Request $request): JsonResponse
    {
        $set = NotificationPreference::where('user_id', $request->user()->id)
            ->pluck('enabled', 'channel');

        $payload = [];

        foreach (['database', 'push'] as $channel) {
            // Absent means on: only exceptions are stored.
            $payload[] = [
                'channel' => $channel,
                'enabled' => (bool) ($set[$channel] ?? true),
            ];
        }

        return successReturnData($payload);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['database', 'push'])],
            'enabled' => ['required', 'boolean'],
        ]);

        NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id, 'channel' => $data['channel']],
            ['enabled' => $data['enabled']]
        );

        return successReturnData($data, __('Notification settings updated.'));
    }
}
