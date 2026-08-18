<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
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
                'read' => !is_null($notification->read_at),
                'created_at' => $notification->created_at->diffForHumans(),
            ]);

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
            'items' => $notifications,
        ]);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
