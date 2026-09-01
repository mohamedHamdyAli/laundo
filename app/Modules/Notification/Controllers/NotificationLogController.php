<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use Illuminate\Http\Request;

/**
 * What the system has been telling people.
 *
 * The question this page exists to answer is «did the customer get it?», which
 * before P11 had no answer at all. The failures counter is the one worth looking
 * at: a push token that Firebase has invalidated fails on every send forever, and
 * the only way to notice is to count.
 */
class NotificationLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = $this->filtered($request)->latest('id')->paginate(20);

        $counts = [
            'sent' => NotificationLog::where('status', NotificationLog::SENT)->count(),
            'failed' => NotificationLog::failures()->count(),
            'skipped' => NotificationLog::where('status', NotificationLog::SKIPPED)->count(),
        ];

        $events = NotificationEvent::cases();

        $view = view('admin.notification.index', compact('logs', 'counts', 'events') + [
            'event' => $request->get('event'),
            'status' => $request->get('status'),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $logs = $this->filtered($request)->latest('id')->paginate(20);

            return response()->json([
                'table' => view('admin.notification.partials._notification_table_body', compact('logs'))->render(),
                'pagination' => $logs->withQueryString()->links()->toHtml(),
            ]);
        }
    }

    private function filtered(Request $request)
    {
        $term = $request->get('query');

        return NotificationLog::with('recipient:id,name,phone')
            ->when($term, fn ($q) => $q->where('title', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%")
                ->orWhereHas('recipient', fn ($r) => $r->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")))
            ->when($request->get('event'), fn ($q) => $q->where('event', $request->get('event')))
            ->when($request->get('status'), fn ($q) => $q->where('status', $request->get('status')));
    }
}
