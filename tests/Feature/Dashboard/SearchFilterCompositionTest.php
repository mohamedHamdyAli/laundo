<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Search and the screen's own filter have to compose.
 *
 * Seven list screens carry a filter beside the search box — a status, a rating
 * band, a notification event — and every one of their controllers reads it from
 * the same request the search term arrives on. Two separate faults meant it did
 * not work:
 *
 *  1. **The JS dropped it.** `setupAjaxSearch` sent only `query` and `page`, so
 *     on every keystroke the filter reverted to the controller's default.
 *     Filtering complaints to «Resolved» and then typing put you back on the
 *     open ones; on Refunds the filter vanished entirely. Fixed with the
 *     `extraParams` hook, which each screen now passes.
 *
 *  2. **Two controllers built an un-grouped OR.** `RefundController@search` and
 *     `NotificationLogController@filtered` chained `where(...)->orWhere(...)` at
 *     the top level and then appended the filter as an `AND`. SQL precedence
 *     turns that into `a OR b OR (c AND filter)` — so a term matching `a` or
 *     `b` ignored the filter completely. Both now go through the `Searchable`
 *     scope, which always wraps its ORs in a group.
 *
 * The notification case is the one that fired in normal use, because that screen
 * actually posted its filters.
 */
class SearchFilterCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    // -----------------------------------------------------------------
    // The un-grouped OR
    // -----------------------------------------------------------------

    #[Test]
    public function a_notification_term_matching_the_title_still_respects_the_filters(): void
    {
        // Two logs. The term matches the TITLE of both — which is the branch that
        // used to sit outside the group and therefore outside the filter.
        NotificationLog::create([
            'event' => 'order_placed', 'channel' => 'push', 'status' => 'sent',
            'title' => 'Shared headline', 'body' => 'first', 'destination' => 'tok-1',
        ]);
        NotificationLog::create([
            'event' => 'order_placed', 'channel' => 'push', 'status' => 'failed',
            'title' => 'Shared headline', 'body' => 'second', 'destination' => 'tok-2',
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(
                route('admin.notification.search', ['query' => 'Shared headline', 'status' => 'failed']),
                ['X-Requested-With' => 'XMLHttpRequest']
            )
            ->assertOk();

        $table = (string) $response->json('table');

        // Only the failed one may come back. Before the fix both did, because
        // `title LIKE …` was OR'd outside the `AND status = 'failed'`.
        $this->assertStringContainsString('second', $table);
        $this->assertStringNotContainsString('first', $table);
    }

    #[Test]
    public function the_notification_event_filter_also_survives_a_search(): void
    {
        NotificationLog::create([
            'event' => 'order_placed', 'channel' => 'push', 'status' => 'sent',
            'title' => 'Same words', 'body' => 'placed-one', 'destination' => 'a',
        ]);
        NotificationLog::create([
            'event' => 'order_delivered', 'channel' => 'push', 'status' => 'sent',
            'title' => 'Same words', 'body' => 'delivered-one', 'destination' => 'b',
        ]);

        $table = (string) $this->actingAs($this->superAdmin())
            ->get(
                route('admin.notification.search', ['query' => 'Same words', 'event' => 'order_delivered']),
                ['X-Requested-With' => 'XMLHttpRequest']
            )
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('delivered-one', $table);
        $this->assertStringNotContainsString('placed-one', $table);
    }

    #[Test]
    public function the_newly_searchable_notification_columns_are_reachable(): void
    {
        NotificationLog::create([
            'event' => 'order_placed', 'channel' => 'sms', 'status' => 'failed',
            'title' => 'Nothing distinctive', 'body' => 'nothing either',
            'destination' => '+201234567890', 'failure_reason' => 'InvalidRegistration',
        ]);

        // `destination` is the number or token the message was aimed at, and
        // `failure_reason` is why it did not arrive — the two things this screen
        // exists to answer, neither of which was searchable.
        foreach (['+201234567890', 'InvalidRegistration', 'sms'] as $term) {
            $table = (string) $this->actingAs($this->superAdmin())
                ->get(
                    route('admin.notification.search', ['query' => $term]),
                    ['X-Requested-With' => 'XMLHttpRequest']
                )
                ->assertOk()
                ->json('table');

            $this->assertStringContainsString(
                'Nothing distinctive',
                $table,
                "searching '{$term}' should have found the log"
            );
        }
    }

    // -----------------------------------------------------------------
    // Wallet: index and search must agree on which rows exist
    // -----------------------------------------------------------------

    #[Test]
    public function wallet_search_hides_the_same_empty_wallets_the_list_hides(): void
    {
        // Built directly rather than through `customer()`, which names every user
        // "Customer" and takes (phone, verified) — two rows that have to be told
        // apart need distinct names.
        $funded = User::create([
            'name' => 'Funded Person', 'phone' => '+201000000501', 'password' => 'password',
            'status' => 'active', 'role_id' => Role::where('slug', Role::USER)->value('id'),
            'phone_verified_at' => now(),
        ]);
        $empty = User::create([
            'name' => 'Empty Person', 'phone' => '+201000000502', 'password' => 'password',
            'status' => 'active', 'role_id' => Role::where('slug', Role::USER)->value('id'),
            'phone_verified_at' => now(),
        ]);

        Wallet::create(['user_id' => $funded->id, 'balance' => 50, 'pending_balance' => 0, 'currency' => 'EGP', 'is_frozen' => false]);
        Wallet::create(['user_id' => $empty->id, 'balance' => 0, 'pending_balance' => 0, 'currency' => 'EGP', 'is_frozen' => false]);

        // The list shows only wallets holding money.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee('Funded Person')
            ->assertDontSee('Empty Person');

        // Search must agree. It did not: searching surfaced the zero-balance
        // wallet the list deliberately hides, so a row appeared while you typed
        // and vanished when you cleared the box.
        $table = (string) $this->actingAs($this->superAdmin())
            ->get(
                route('admin.wallet.search', ['query' => 'Person']),
                ['X-Requested-With' => 'XMLHttpRequest']
            )
            ->assertOk()
            ->json('table');

        $this->assertStringContainsString('Funded Person', $table);
        $this->assertStringNotContainsString('Empty Person', $table);
    }

    // -----------------------------------------------------------------
    // The JS half
    // -----------------------------------------------------------------

    #[Test]
    public function every_filtered_screen_forwards_its_filter_when_searching(): void
    {
        // The controllers were always written to compose; the JS was what
        // prevented it. A static check because it covers all eight on every run.
        $expected = [
            'order' => ['status'],
            'complaint' => ['status'],
            'rating' => ['band'],
            'recurrence' => ['status'],
            'payment' => ['status'],
            'earning' => ['status'],
            'refund' => ['status'],
            'notification' => ['event', 'status'],
        ];

        $problems = [];

        foreach ($expected as $screen => $params) {
            $path = resource_path("views/admin/{$screen}/index.blade.php");

            if (! file_exists($path)) {
                $problems[] = "{$screen}: index view missing";

                continue;
            }

            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'extraParams')) {
                $problems[] = "{$screen}: search does not forward its filter (no extraParams)";

                continue;
            }

            foreach ($params as $param) {
                if (! preg_match('/\b'.preg_quote($param, '/').':\s*\$\(/', $source)) {
                    $problems[] = "{$screen}: extraParams does not send '{$param}'";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function the_search_helper_forwards_extra_params_at_request_time(): void
    {
        // Read per request, not captured once at page load — otherwise the value
        // is whatever the filter held when the page rendered.
        $js = (string) file_get_contents(resource_path('views/layouts/footer_script.blade.php'));

        $this->assertStringContainsString('config.extraParams', $js);
        $this->assertStringContainsString("typeof config.extraParams === 'function'", $js);
    }
}
