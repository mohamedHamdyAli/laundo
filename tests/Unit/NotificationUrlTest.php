<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A notification's link is stored now and clicked later, somewhere else.
 *
 * That is the whole reason it must be a **path**. An absolute URL bakes in
 * whichever host generated it, and that is not reliably the host the reader is
 * on: anything raised from the console or from tinker gets `APP_URL` or
 * `localhost`, and one written by a request behind Cloudflare's Flexible SSL
 * gets whatever scheme survived the edge.
 *
 * This is not hypothetical. A live notification shipped reading
 * `http://localhost/admin/driver-record-submission`, because the row that
 * created it was written from a console session rather than from a browser —
 * and every other notification in the same list stored `/admin/order/show/33`
 * and worked.
 *
 * `route()` and `url()` are the two ways back into that, so the test is against
 * the source rather than against a rendered payload: a notifier is only wrong
 * once somebody runs it, and by then the bad link is in somebody's bell.
 */
class NotificationUrlTest extends TestCase
{
    #[Test]
    public function no_notifier_builds_an_absolute_link(): void
    {
        $offenders = [];

        foreach (glob(app_path('Modules/Notification/Services/*.php')) as $file) {
            foreach (file($file) as $number => $line) {
                // Only the argument position, not a mention in a comment.
                if (preg_match('/^\s*(route|url)\(/', $line)) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    #[Test]
    public function the_notifiers_point_at_routes_that_exist(): void
    {
        // The other half of the trade. Writing the path by hand means nothing
        // checks it any more, so this does: a link into a screen that was
        // renamed is a bell entry that 404s, which is worse than an absolute URL
        // because it looks right until somebody clicks it.
        $paths = [
            '/admin/driver-record-submission',
            '/admin/driver-application',
            '/admin/laundry/show/1',
            '/admin/order/show/1',
        ];

        foreach ($paths as $path) {
            $this->assertNotNull(
                app('router')->getRoutes()->match(
                    Request::create($path, 'GET')
                ),
                "{$path} is linked from a notification and matches no route"
            );
        }
    }
}
