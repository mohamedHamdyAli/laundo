<?php

namespace Tests\Feature\Api;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Coupon\Services\ReferralService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «ادعُ أصدقاءك — خصومات حصرية لك ولهم».
 *
 * The owner's decision was both sides, **after the friend's first paid order**.
 * That timing is the feature: a reward at sign-up is free to manufacture, and any
 * programme that pays on registration is one somebody farms with a handful of
 * phone numbers. Most of these tests are about the ways people try.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
    }

    private function reward(?string $value, string $type = 'percentage'): void
    {
        if ($value === null) {
            Setting::whereIn('key', ['Referral_Reward_Value', 'Referral_Reward_Type'])->delete();
        } else {
            Setting::updateOrCreate(['key' => 'Referral_Reward_Value'], ['value' => $value]);
            Setting::updateOrCreate(['key' => 'Referral_Reward_Type'], ['value' => $type]);
        }

        Cache::flush();
    }

    private function register(string $phone, ?string $code = null): User
    {
        $this->postJson('/api/v1/auth/register', array_filter([
            'name' => 'Customer '.$phone,
            'phone' => $phone,
            'password' => 'password',
            'password_confirmation' => 'password',
            'accepted_terms' => true,
            'referral_code' => $code,
        ]))->assertSuccessful();

        return User::where('phone', $phone)->firstOrFail();
    }

    private function payFor(User $customer): Order
    {
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->update(['payment_status' => 'paid', 'paid_at' => now()]);

        return $order->fresh();
    }

    // ------------------------------------------------------------- the code

    #[Test]
    public function every_customer_gets_a_code(): void
    {
        $customer = $this->customer('+201055550001');

        $this->assertStringStartsWith('LAUNDO-', (string) $customer->fresh()->referral_code);
    }

    #[Test]
    public function two_customers_never_share_one(): void
    {
        $a = $this->customer('+201055550001')->fresh();
        $b = $this->customer('+201055550002')->fresh();

        $this->assertNotSame($a->referral_code, $b->referral_code);
    }

    #[Test]
    public function a_driver_has_no_code(): void
    {
        $this->assertNull($this->driverUser('+201066660001')->fresh()->referral_code);
    }

    // ------------------------------------------------------------- signing up

    #[Test]
    public function a_friend_who_uses_the_code_is_attached_to_the_inviter(): void
    {
        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);

        $this->assertSame($inviter->id, $friend->referred_by);
    }

    #[Test]
    public function a_wrong_code_does_not_stop_somebody_registering(): void
    {
        // A mistyped code must not be the reason a customer cannot sign up, and
        // telling a stranger whether a code exists is a way to enumerate them.
        $friend = $this->register('+201055559999', 'LAUNDO-NOPE12');

        $this->assertNull($friend->referred_by);
    }

    #[Test]
    public function nobody_invites_themselves(): void
    {
        $customer = $this->customer('+201055550001')->fresh();

        app(ReferralService::class)
            ->link($customer, $customer->referral_code);

        $this->assertNull($customer->fresh()->referred_by);
    }

    // ------------------------------------------------------------ the payout

    #[Test]
    public function both_sides_are_paid_once_the_friend_pays(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);

        $this->assertSame(0, Coupon::count());

        $this->payFor($friend);

        // «خصومات حصرية لك ولهم» — one each, and only now.
        $this->assertSame(1, Coupon::where('user_id', $inviter->id)->count());
        $this->assertSame(1, Coupon::where('user_id', $friend->id)->count());
    }

    #[Test]
    public function signing_up_alone_earns_nothing(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $this->register('+201055559999', $inviter->referral_code);

        // The whole point of the timing: five phone numbers must not mint five
        // coupons without anybody washing anything.
        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function a_second_order_pays_nothing_more(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);

        $this->payFor($friend);
        $this->payFor($friend);

        $this->assertSame(2, Coupon::count());
        $this->assertNotNull($friend->fresh()->referral_rewarded_at);
    }

    #[Test]
    public function an_order_marked_paid_twice_pays_once(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);

        $order = $this->payFor($friend);
        // A webhook arriving after the driver already collected cash.
        $order->update(['paid_at' => now()->addSecond()]);

        $this->assertSame(2, Coupon::count());
    }

    #[Test]
    public function an_uninvited_customer_pays_and_nothing_happens(): void
    {
        $this->reward('20');

        $this->payFor($this->register('+201055559999'));

        $this->assertSame(0, Coupon::count());
    }

    #[Test]
    public function no_configured_reward_issues_no_coupon(): void
    {
        $this->reward(null);

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);
        $this->payFor($friend);

        // The size of a discount is the owner's decision, not a default worth
        // inventing. The referral is still recorded.
        $this->assertSame(0, Coupon::count());
        $this->assertNotNull($friend->fresh()->referral_rewarded_at);
    }

    #[Test]
    public function turning_the_reward_on_later_does_not_pay_retroactively(): void
    {
        $this->reward(null);

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);
        $this->payFor($friend);

        $this->reward('20');
        $this->payFor($friend);

        // The referral was settled on the day it happened. Filling in the
        // setting must not open a bill for every friend who ever ordered.
        $this->assertSame(0, Coupon::count());
    }

    // ------------------------------------------------------- the coupon itself

    #[Test]
    public function the_reward_belongs_to_one_person(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);
        $this->payFor($friend);

        $theirs = Coupon::where('user_id', $inviter->id)->firstOrFail();
        $stranger = $this->customer('+201055550003');

        // Coupons here have always been public codes limited by a redemption
        // count. A personal reward issued as a public code is a reward for
        // whoever hears about it first.
        $this->expectException(\RuntimeException::class);
        app(CouponService::class)
            ->validate($theirs->code, $stranger, 500);
    }

    #[Test]
    public function the_person_it_belongs_to_can_spend_it(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $friend = $this->register('+201055559999', $inviter->referral_code);
        $this->payFor($friend);

        $theirs = Coupon::where('user_id', $inviter->id)->firstOrFail();

        $result = app(CouponService::class)
            ->validate($theirs->code, $inviter, 500);

        $this->assertEquals(100.0, $result['discount']);
    }

    #[Test]
    public function a_public_coupon_still_works_for_everybody(): void
    {
        $coupon = Coupon::create([
            'code' => 'WELCOME10',
            'name' => json_encode(['en' => 'Welcome', 'ar' => 'ترحيب'], JSON_UNESCAPED_UNICODE),
            'type' => 'percentage', 'value' => 10,
            'max_redemptions' => 100, 'max_per_user' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        $result = app(CouponService::class)
            ->validate($coupon->code, $this->customer('+201055550001'), 500);

        $this->assertEquals(50.0, $result['discount']);
    }

    // ------------------------------------------------------------- the screen

    #[Test]
    public function the_account_screen_reports_both_halves(): void
    {
        $this->reward('20');

        $inviter = $this->customer('+201055550001')->fresh();
        $ordered = $this->register('+201055559998', $inviter->referral_code);
        $this->register('+201055559999', $inviter->referral_code);
        $this->payFor($ordered);

        $data = $this->actingAs($inviter)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->json('data');

        // Somebody who shares a code and sees nothing happen shares it once. The
        // gap between the two numbers is the whole story of the programme.
        $this->assertSame($inviter->referral_code, $data['code']);
        $this->assertSame(2, $data['invited']);
        $this->assertSame(1, $data['ordered']);
        $this->assertCount(1, $data['rewards']);
        $this->assertStringContainsString((string) $inviter->referral_code, $data['share_text']);
    }

    #[Test]
    public function the_register_screen_can_read_the_offer_before_anybody_signs_in(): void
    {
        $this->reward('20');

        $this->getJson('/api/v1/referral-terms')
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.value', 20);
    }

    #[Test]
    public function an_unconfigured_programme_says_so(): void
    {
        $this->reward(null);

        // An app that hardcodes «خصم 20%» lies the day the owner changes it, and
        // advertising a programme that pays nothing is worse than silence.
        $this->getJson('/api/v1/referral-terms')
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }
}
