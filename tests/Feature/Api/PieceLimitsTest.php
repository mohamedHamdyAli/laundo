<?php

namespace Tests\Feature\Api;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\PieceLimits;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «التحكم في عدد القطع» — the client's sheet, item 19 (2026-11-08): the most
 * pieces one order may carry (`Max_Pieces_Per_Order`) and the most a pickup
 * window may collect in a day (`time_slots.max_pieces`). Over either, the
 * order is refused; a window without room is reported full.
 */
class PieceLimitsTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private User $customer;

    private TimeSlot $morning;

    private const DAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->morning = TimeSlot::create([
            'start_time' => '09:00:00', 'end_time' => '12:00:00', 'applies_to' => 'both',
            'sort_order' => 1, 'status' => 'active', 'max_pieces' => 10,
        ]);
        $this->customer = $this->customer();

        config(['app.display_timezone' => 'Africa/Cairo']);
        $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'Africa/Cairo'));
    }

    // ---------------------------------------------------------------- per order

    #[Test]
    public function an_order_over_the_limit_is_refused_beside_its_basket(): void
    {
        $this->setting(5);

        $this->place(6)->assertStatus(422)
            ->assertJsonPath('errors.items.0', __('One order can carry up to :max pieces.', ['max' => 5]));
        $this->assertSame(0, Order::count());

        $this->place(5)->assertCreated();
    }

    #[Test]
    public function blank_is_no_limit_and_the_app_is_told_either_way(): void
    {
        $this->getJson('/api/v1/app-settings', $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.max_pieces_per_order', null);
        $this->place(9, slot: false)->assertCreated();

        $this->setting(8);
        $this->getJson('/api/v1/app-settings', $this->apiHeaders())
            ->assertOk()->assertJsonPath('data.max_pieces_per_order', 8);
    }

    #[Test]
    public function the_service_refuses_it_too_whoever_calls_it(): void
    {
        $this->setting(3);

        $this->expectExceptionMessage('too_many_pieces');
        app(OrderService::class)->place($this->customer, $this->payload(4, slot: false));
    }

    // ---------------------------------------------------------------- per window

    #[Test]
    public function a_window_takes_pieces_until_it_is_full(): void
    {
        $this->place(8)->assertCreated();

        // Room for two: three is refused, beside the window, saying how many.
        $this->place(3)->assertStatus(422)->assertJsonPath(
            'errors.pickup_slot_id.0',
            __('This pickup window has room for :count more pieces only. Please choose another one.', ['count' => 2]),
        );
        $this->place(2)->assertCreated();

        // Now full: one more piece is refused as a full window.
        $this->place(1)->assertStatus(422)->assertJsonPath(
            'errors.pickup_slot_id.0',
            __('This pickup window is fully booked. Please choose another one.'),
        );
        $this->assertSame(10, app(PieceLimits::class)->bookedInWindow($this->morning, self::DAY));
    }

    #[Test]
    public function the_app_is_shown_the_room_and_a_window_that_cannot_take_the_basket_is_full(): void
    {
        $this->place(7)->assertCreated();

        $slot = fn (string $query) => collect($this->getJson('/api/v1/time-slots?'.$query, $this->apiHeaders())
            ->assertOk()->json('data'))->firstWhere('id', $this->morning->id);

        $this->assertSame(3, $slot('type=pickup&date='.self::DAY)['pieces_left']);
        $this->assertFalse($slot('type=pickup&date='.self::DAY)['is_full']);
        // A basket of four does not fit in three.
        $this->assertTrue($slot('type=pickup&date='.self::DAY.'&pieces=4')['is_full']);
        $this->assertFalse($slot('type=pickup&date='.self::DAY.'&pieces=3')['is_full']);
        // A delivery carries no new pieces: the same window is open for one.
        $this->assertFalse($slot('type=delivery&date='.self::DAY.'&pieces=4')['is_full']);
        // …and is not given a pickup figure it could misread (code review).
        $this->assertNull($slot('type=delivery&date='.self::DAY)['pieces_left']);
        // Another day is empty.
        $this->assertSame(10, $slot('type=pickup&date=2026-11-11')['pieces_left']);
    }

    #[Test]
    public function a_cancelled_order_gives_its_pieces_back(): void
    {
        $this->place(10)->assertCreated();
        $this->place(1)->assertStatus(422);

        app(OrderService::class)->cancel(Order::firstOrFail(), $this->customer);

        $this->place(10)->assertCreated();
    }

    #[Test]
    public function the_service_holds_the_window_too(): void
    {
        $this->place(9)->assertCreated();

        $this->expectExceptionMessage('window_pieces_full');
        app(OrderService::class)->place($this->customer, $this->payload(2));
    }

    #[Test]
    public function a_window_with_no_limit_takes_any_number(): void
    {
        $this->morning->update(['max_pieces' => null]);

        $this->place(40)->assertCreated();
        $this->assertNull(app(PieceLimits::class)->roomInWindow($this->morning, self::DAY));
    }

    #[Test]
    public function a_quoted_service_has_no_basket_and_is_held_to_neither_limit(): void
    {
        // Code review (2026-11-08): the request counted the raw basket, the
        // service counts priced pieces, and a quoted service prices none — its
        // pieces are known once the laundry counts. Both now agree.
        $this->setting(3);
        $this->morning->update(['max_pieces' => 2]);
        $this->cover(Laundry::withoutGlobalScopes()->firstOrFail(), $this->geo['zones'][0]->id, $this->catalog['quoted']->id);

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/orders', ['service_id' => $this->catalog['quoted']->id] + $this->payload(10), $this->apiHeaders())
            ->assertCreated();
    }

    // ---------------------------------------------------------------- the panel

    #[Test]
    public function the_panel_sets_and_clears_both_limits(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.time_slot.update', $this->morning->id), [
            'start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both', 'status' => 'active', 'max_pieces' => 25,
        ])->assertSessionHasNoErrors();
        $this->assertSame(25, $this->morning->fresh()->max_pieces);

        $this->actingAs($admin)->put(route('admin.time_slot.update', $this->morning->id), [
            'start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both', 'status' => 'active', 'max_pieces' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->morning->fresh()->max_pieces);

        $this->actingAs($admin)->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()->assertSee('name="Max_Pieces_Per_Order"', false);
        $this->actingAs($admin)->get(route('admin.time_slot.edit', $this->morning->id))
            ->assertOk()->assertSee('name="max_pieces"', false);
    }

    private function setting(?int $max): void
    {
        Setting::updateOrCreate(['key' => PieceLimits::SETTING], ['value' => $max]);
        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $pieces, bool $slot = true): array
    {
        return [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => $pieces]],
            'payment_method' => 'cash',
            'accepts_review_terms' => true,
        ] + ($slot ? ['pickup_date' => self::DAY, 'pickup_slot_id' => $this->morning->id] : []);
    }

    private function place(int $pieces, bool $slot = true)
    {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/orders', $this->payload($pieces, $slot), $this->apiHeaders());
    }
}
