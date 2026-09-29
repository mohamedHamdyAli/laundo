<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Services\OrderService;
use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that turned every commission rule into the laundry's share.
 *
 * The number on a rule used to be what the platform took and is now what the
 * laundry receives. The one property that matters is that **no laundry's payout
 * moves on the day the meaning does** — so each case is asserted by what the
 * laundry is paid on the same basis before and after.
 */
class LaundryShareMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const BASIS = 200.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Cache::flush();
    }

    private function rule(string $basis, float $value, string $status = 'active'): CommissionRule
    {
        return CommissionRule::create([
            'name' => json_encode(['en' => "Old {$basis} {$value}"], JSON_UNESCAPED_UNICODE),
            'basis' => $basis,
            'rate' => $basis === 'percent' ? $value : null,
            'amount' => $basis === 'fixed' ? $value : null,
            'status' => $status,
        ]);
    }

    private function laundry(string $tag, int $n): Laundry
    {
        return $this->laundryWithOwner($tag, '+2010111200'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), '+2010111300'.str_pad((string) $n, 2, '0', STR_PAD_LEFT))['laundry'];
    }

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_09_27_100100_turn_commission_rules_into_laundry_shares.php');
        $migration->up();
    }

    private function laundryAmount(Laundry $laundry): ?float
    {
        $split = app(SettlementService::class)->splitFor($laundry->fresh(), self::BASIS);

        return $split['share_rate'] === null ? null : $split['laundry'];
    }

    #[Test]
    public function every_laundry_is_paid_what_it_was_paid_before(): void
    {
        // Before: the platform took 10% of the single laundry, 10% + 5% of the
        // stacked one, and nothing of the one with no rule.
        $general = $this->rule('percent', 10);
        $extra = $this->rule('percent', 5);

        $single = $this->laundry('Single', 1);
        $stacked = $this->laundry('Stacked', 2);
        $unruled = $this->laundry('Unruled', 3);

        $general->laundries()->attach([$single->id, $stacked->id]);
        $extra->laundries()->attach([$stacked->id]);

        $this->migrate();

        $this->assertSame(180.0, $this->laundryAmount($single));
        $this->assertSame(170.0, $this->laundryAmount($stacked));
        $this->assertSame(200.0, $this->laundryAmount($unruled));

        // One active share each — they no longer stack.
        foreach ([$single, $stacked, $unruled] as $laundry) {
            $this->assertSame(1, $laundry->fresh()->commissionRules()->where('status', 'active')->count());
        }
    }

    #[Test]
    public function an_order_in_flight_with_a_coupon_pays_its_laundry_what_it_would_have(): void
    {
        // Before: the platform took 10% of the washing after the discount, so on
        // a 200 wash with a 20 coupon the laundry was due (200 − 20) × 90% = 162.
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();
        $tenant = $this->laundryWithOwner('Flight', '+201011129901', '+201011129902');
        $this->cover($tenant['laundry'], $geo['zones'][0]->id, $catalog['service']->id);
        $this->rule('percent', 10)->laundries()->attach([$tenant['laundry']->id]);

        $customer = $this->customer('+201099889901');
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $catalog['service']->id,
            'pickup_address_id' => $this->addressFor($customer, $geo['zones'][0])->id,
            'items' => [['item_id' => $catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
        // Placed before the coupon's bearer was recorded: a discount, no bearer.
        $order->forceFill(['discount_total' => 20, 'discount_laundry_share' => null])->save();

        $this->migrate();
        (require database_path('migrations/2026_09_28_100000_keep_in_flight_coupon_payouts_where_they_were.php'))->up();

        $order = $order->fresh();
        $this->assertSame('90.00', $order->discount_laundry_share);

        $split = app(SettlementService::class)->splitFor(
            $tenant['laundry']->fresh(), self::BASIS, 20, 20, $order->laundryShareOfDiscount()
        );
        $this->assertSame(162.0, $split['laundry']);
    }

    #[Test]
    public function a_settled_order_is_never_restamped(): void
    {
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();
        $tenant = $this->laundryWithOwner('Done', '+201011129911', '+201011129912');
        $this->cover($tenant['laundry'], $geo['zones'][0]->id, $catalog['service']->id);
        $this->rule('percent', 10)->laundries()->attach([$tenant['laundry']->id]);

        $customer = $this->customer('+201099889911');
        $order = app(OrderService::class)->place($customer, [
            'service_id' => $catalog['service']->id,
            'pickup_address_id' => $this->addressFor($customer, $geo['zones'][0])->id,
            'items' => [['item_id' => $catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);
        $order->forceFill(['discount_total' => 20, 'discount_laundry_share' => null])->save();

        OrderSettlement::withoutGlobalScopes()->forceCreate([
            'order_id' => $order->id, 'laundry_id' => $tenant['laundry']->id,
            'basis' => 180, 'commission_rate' => 10, 'commission_amount' => 18, 'laundry_amount' => 162,
            'tax_amount' => 0, 'status' => 'settled',
        ]);

        $this->migrate();
        (require database_path('migrations/2026_09_28_100000_keep_in_flight_coupon_payouts_where_they_were.php'))->up();

        $this->assertNull($order->fresh()->discount_laundry_share);
    }

    #[Test]
    public function a_single_rule_is_flipped_in_place_and_keeps_its_laundries(): void
    {
        $general = $this->rule('percent', 10);
        $a = $this->laundry('A', 4);
        $b = $this->laundry('B', 5);
        $general->laundries()->attach([$a->id, $b->id]);

        $this->migrate();

        $general = $general->fresh();
        $this->assertEquals(90.0, (float) $general->rate);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $general->laundries()->pluck('laundries.id')->all());
    }

    #[Test]
    public function an_inactive_rule_is_flipped_too_so_switching_it_on_later_means_what_it_says(): void
    {
        $dormant = $this->rule('percent', 25, 'inactive');

        $this->migrate();

        $this->assertEquals(75.0, (float) $dormant->fresh()->rate);
        $this->assertSame('inactive', $dormant->fresh()->status);
    }

    #[Test]
    public function a_laundry_on_a_fixed_charge_is_not_guessed_at(): void
    {
        // «90% less 5 a job» has no percentage that means the same on every
        // order. The laundry is left with no share so its next settlement waits,
        // and the fixed rule is switched off with its attachment kept as the
        // record of what it was.
        $percent = $this->rule('percent', 10);
        $fixed = $this->rule('fixed', 5);
        $laundry = $this->laundry('Fixed', 6);

        $percent->laundries()->attach($laundry->id);
        $fixed->laundries()->attach($laundry->id);

        $this->migrate();

        $this->assertNull($this->laundryAmount($laundry));
        $this->assertSame('inactive', $fixed->fresh()->status);
        $this->assertTrue($fixed->fresh()->laundries()->whereKey($laundry->id)->exists());
        $this->assertFalse($percent->fresh()->laundries()->whereKey($laundry->id)->exists());
    }

    #[Test]
    public function laundries_on_the_same_combined_share_share_one_rule(): void
    {
        $ten = $this->rule('percent', 10);
        $five = $this->rule('percent', 5);

        $a = $this->laundry('A', 7);
        $b = $this->laundry('B', 8);
        $ten->laundries()->attach([$a->id, $b->id]);
        $five->laundries()->attach([$a->id, $b->id]);

        $this->migrate();

        $ruleA = $a->fresh()->commissionRules()->where('status', 'active')->value('commission_rules.id');
        $ruleB = $b->fresh()->commissionRules()->where('status', 'active')->value('commission_rules.id');

        $this->assertNotNull($ruleA);
        $this->assertSame($ruleA, $ruleB);
        $this->assertEquals(85.0, (float) CommissionRule::find($ruleA)->rate);
    }
}
