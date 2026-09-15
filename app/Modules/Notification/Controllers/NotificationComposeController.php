<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Enums\NotificationAudience;
use App\Modules\Notification\Requests\ManualNotificationRequest;
use App\Modules\Notification\Services\ManualNotifier;
use App\Support\LaundryContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Saying something the system has no event for.
 *
 * Separate from `NotificationLogController`, which reads. That one answers «did
 * it arrive?»; this one is the only place in the panel that *causes* a
 * notification without a business action behind it, and the two deserve to be
 * read apart.
 */
class NotificationComposeController extends Controller
{
    public function __construct(private readonly ManualNotifier $notifier) {}

    public function create(Request $request)
    {
        $this->refuseTenant();

        $audience = NotificationAudience::tryFrom((string) $request->get('audience'))
            ?? NotificationAudience::Customer;

        return view('admin.notification.compose', [
            'audiences' => NotificationAudience::cases(),
            'audience' => $audience,
            // Every audience's list, so switching between them keeps what has
            // already been typed. A page reload would be simpler and would throw
            // the message away, which is the thing this panel was recently fixed
            // to stop doing.
            'targets' => collect(NotificationAudience::cases())
                ->mapWithKeys(fn (NotificationAudience $case) => [
                    $case->value => $this->notifier->targets($case),
                ])
                ->all(),
            'inlineLimit' => $this->notifier->inlineLimit(),
        ]);
    }

    public function store(ManualNotificationRequest $request)
    {
        $this->refuseTenant();

        $audience = $request->audience();
        $targetId = $request->targetId();

        /*
         * Raised as a validation error rather than flashed, so it lands beside
         * the recipient field — which is what it is about — and so the background
         * submit in `form-validation.js` paints it without losing the message
         * that has already been typed. A 302 with a flash would reload the page
         * to say the same thing less usefully.
         */
        if ($blocker = $this->notifier->blocker($audience, $targetId)) {
            throw ValidationException::withMessages(['target' => $blocker]);
        }

        $result = $this->notifier->send(
            $audience,
            $targetId,
            (string) $request->validated('title'),
            (string) $request->validated('body'),
            $request->user(),
        );

        /*
         * «Sending», not «sent», when the worker has it. The difference is the
         * whole reason the flash distinguishes them: telling somebody a message
         * has gone when it is still on a queue is how a stopped worker becomes
         * invisible, and the log below is where the answer actually is.
         */
        return redirect()
            ->route('admin.notification.index')
            ->with('success', match (true) {
                $result['queued'] => __('Sending to :count people. They will appear in the log as they go out.', ['count' => $result['count']]),
                $result['count'] === 1 => __('Sent.'),
                default => __('Sent to :count people.', ['count' => $result['count']]),
            });
    }

    /**
     * A laundry account may not use this screen, whatever its ticks say.
     *
     * The permission system grants a *screen*; it has no vocabulary for «to
     * whom». A laundry owner holding `notification_log.create` could write to
     * every customer on the platform, which no tick on the roles page looks like
     * it is granting — and the tenant scope, which confines them everywhere else,
     * does not reach a recipient list assembled from a role slug.
     *
     * `currentId()` is null for a super admin and for a moderator, and that null
     * is the same bypass the rest of the panel already trusts.
     */
    private function refuseTenant(): void
    {
        abort_unless(
            LaundryContext::currentId() === null,
            403,
            __('This screen writes to people outside your laundry.')
        );
    }
}
