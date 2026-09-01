<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The operator's own inbox.
 *
 * Worth separating from `NotificationLogController`, which the route names sit one
 * letter apart from: **this** reads Laravel's `notifications` table, the inbox
 * `NotificationDispatcher::toDatabase()` writes to, and it is what the topbar bell
 * shows. That one reads `notification_logs`, an audit record of every delivery
 * attempt on every channel to customers and drivers. Different tables, different
 * audiences, both correct.
 *
 * `index` exists because the bell is a ten-item dropdown. An operations alert that
 * scrolls past the tenth was simply gone, which is a poor place to put the only
 * warning that a task has had no driver for six hours.
 */
class NotificationController extends Controller
{
    /**
     * Everything this operator has been sent, oldest unread first.
     */
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            // `reorder()` first, and it is not optional. Laravel's `notifications()`
            // relation is defined as `morphMany(...)->latest()`, so it arrives
            // already sorted by created_at — anything added here would come second
            // and never decide anything. Without this the unread-first rule below
            // did nothing at all.
            ->reorder()
            // Unread first, then newest: an alert from Tuesday nobody has opened
            // matters more than one from an hour ago that has been dealt with.
            ->orderByRaw('read_at is not null')
            ->latest()
            ->paginate(20);

        $view = view('admin.notification.mine', [
            'notifications' => $notifications,
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function unread(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->take(10)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? '',
                'message' => $notification->data['message'] ?? '',
                'url' => $notification->data['url'] ?? null,
                'read' => ! is_null($notification->read_at),
                'created_at' => $notification->created_at->diffForHumans(),
            ]);

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
            'items' => $notifications,
        ]);
    }

    /**
     * Answers the bell's fetch and the inbox page's form.
     *
     * The bell posts by AJAX and wants JSON; the page posts a plain form and wants
     * to come back to itself. Returning JSON to a form navigation shows the user a
     * page of raw JSON, which is how a working endpoint looks broken.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : back();
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : back()->with('success', __('All notifications marked as read.'));
    }
}
