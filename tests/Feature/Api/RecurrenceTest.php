<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Repeat schedules.
 *
 * The rule these tests exist to protect: **a schedule asks, it does not order.**
 * The scheduler must be able to run any number of times without creating a single
 * order, and without asking the same question twice.
 *
 * Saying yes does not order either - it hands back a basket. Only POST /orders
 * carrying the prompt back closes the question, which is what lets a recurring
 * order be re-priced, paid for and capacity-checked like any other.
 */
class RecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private array $catalog;

    private array $geo;

    private $customer;

    private $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->geo['zones'][0]->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);

        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        $this->customer = $this->customer();
        $this->address = $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    #[Test]
    public function a_customer_can_save_a_weekly_schedule(): void
    {
        Sanctum::actingAs($this->customer);

        $response = $this->postJson('/api/v1/recurrences', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ], $this->apiHeaders());

        $response->assertCreated()
            ->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.day_of_week', 1)
            ->assertJsonPath('data.status', 'active');

        $schedule = OrderRecurrence::firstOrFail();

        // The first question is in the future, on the chosen weekday, and no order
        // exists yet.
        $this->assertTrue($schedule->next_prompt_on->isAfter(now()->startOfDay()));
        $this->assertSame(1, $schedule->next_prompt_on->dayOfWeekIso);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_weekly_schedule_must_name_its_weekday(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/recurrences', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'weekly',
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ], $this->apiHeaders())->assertStatus(422);

        // Monthly repeats from its own start date, so it needs none.
        $this->postJson('/api/v1/recurrences', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'monthly',
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ], $this->apiHeaders())->assertCreated();
    }

    #[Test]
    public function the_scheduler_asks_and_creates_nothing(): void
    {
        $schedule = $this->dueSchedule();

        $this->artisan('orders:prompt-recurring')->assertSuccessful();

        $this->assertSame(1, RecurrencePrompt::count());
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $prompt = RecurrencePrompt::firstOrFail();
        $this->assertNull($prompt->answer);
        $this->assertNotNull($prompt->prompted_at);
        $this->assertSame($schedule->id, $prompt->recurrence_id);
    }

    #[Test]
    public function running_the_scheduler_twice_asks_once(): void
    {
        $this->dueSchedule();

        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();

        $this->assertSame(1, RecurrencePrompt::count());
    }

    #[Test]
    public function confirming_a_prompt_hands_back_a_basket_and_creates_nothing(): void
    {
        $schedule = $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        $response = $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.prompt_id', $prompt->id)
            ->assertJsonPath('data.recurrence_id', $schedule->id)
            ->assertJsonPath('data.for_date', $prompt->prompted_for->toDateString())
            ->assertJsonPath('data.service_id', $this->catalog['service']->id)
            ->assertJsonPath('data.pickup_address_id', $this->address->id)
            // One address on the schedule, so the wizard opens with both legs on it.
            ->assertJsonPath('data.delivery_address_id', $this->address->id)
            ->assertJsonPath('data.items.0.qty', 2);

        // The whole point: nothing was bought, and the question is still open so
        // a customer who closes the app is asked again.
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertNull($prompt->fresh()->answer);

        // And asking twice is not an error - it is the same basket.
        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders())->assertOk();
    }

    #[Test]
    public function an_order_placed_from_a_prompt_closes_it_and_carries_the_schedule(): void
    {
        $schedule = $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        // The wizard's own data, not the schedule's: a different quantity, and a
        // payment method the schedule never held.
        $this->postJson('/api/v1/orders', [
            'prompt_id' => $prompt->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 5]],
            'pickup_date' => now()->addDay()->toDateString(),
            'payment_method' => 'cash',
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($schedule->id, $order->recurrence_id);
        $this->assertSame('confirmed', $prompt->fresh()->answer);
        $this->assertSame($order->id, $prompt->fresh()->order_id);
        $this->assertNotNull($prompt->fresh()->answered_at);

        // 5 x 17, the basket the customer actually agreed to - the schedule still
        // says 2.
        $this->assertSame('85.00', $order->estimated_subtotal);
        $this->assertSame([['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]], $schedule->fresh()->items);
    }

    #[Test]
    public function the_wizards_payment_method_reaches_a_recurring_order(): void
    {
        // The old one-tap confirm never sent one, so every recurring order was
        // stored with payment_method null - which also zeroed the cash surcharge
        // for a customer who was about to pay cash.
        Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => '15']);

        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/v1/orders', [
            'prompt_id' => $prompt->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => 'cash',
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('cash', $order->payment_method?->value ?? $order->payment_method);
        $this->assertSame('15.00', $order->cash_surcharge);
    }

    #[Test]
    public function a_recurring_order_is_held_to_the_window_cap(): void
    {
        // Exempt before this change, because the customer had no slot picker to
        // be sent back to. They have one now, so the back door closes.
        $slot = TimeSlot::create([
            'start_time' => '15:00:00', 'end_time' => '18:00:00',
            'applies_to' => 'both', 'capacity' => 1, 'sort_order' => 1, 'status' => 'active',
        ]);
        $tomorrow = now()->addDay()->toDateString();

        Sanctum::actingAs($this->customer);

        $body = [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'pickup_slot_id' => $slot->id,
            'pickup_date' => $tomorrow,
            'accepts_review_terms' => true,
        ];

        $this->postJson('/api/v1/orders', $body, $this->apiHeaders())->assertCreated();

        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        $this->postJson('/api/v1/orders', ['prompt_id' => $prompt->id] + $body, $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.pickup_slot_id.0', 'This window is fully booked. Please choose another one.');

        // Refused, so the question stays open for a window that is not full.
        $this->assertNull($prompt->fresh()->answer);
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_customer_cannot_place_an_order_against_someone_elses_prompt(): void
    {
        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        $stranger = $this->customer('+201077665533');
        $strangerAddress = $this->addressFor($stranger, $this->geo['zones'][0]);
        Sanctum::actingAs($stranger);

        // The prompt exists, so validation passes - ownership is what refuses it.
        $this->postJson('/api/v1/orders', [
            'prompt_id' => $prompt->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $strangerAddress->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ], $this->apiHeaders())->assertNotFound();

        $this->assertNull($prompt->fresh()->answer);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_schedule_keeps_its_basket_until_the_customer_says_otherwise(): void
    {
        $schedule = $this->dueSchedule();
        $newItem = $this->catalog['items'][1] ?? $this->catalog['items'][0];

        Sanctum::actingAs($this->customer);

        $this->putJson("/api/v1/recurrences/{$schedule->id}/items", [
            'items' => [['item_id' => $newItem->id, 'qty' => 7]],
        ], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.items.0.item_id', $newItem->id)
            ->assertJsonPath('data.items.0.qty', 7);

        $this->assertSame([['item_id' => $newItem->id, 'qty' => 7]], $schedule->fresh()->items);

        // Everything else about the schedule is its identity, and untouched.
        $this->assertSame('weekly', $schedule->fresh()->frequency);
        $this->assertSame(1, $schedule->fresh()->day_of_week);
    }

    #[Test]
    public function an_empty_basket_cannot_be_saved_to_a_schedule(): void
    {
        $schedule = $this->dueSchedule();

        Sanctum::actingAs($this->customer);

        $this->putJson("/api/v1/recurrences/{$schedule->id}/items", ['items' => []], $this->apiHeaders())
            ->assertStatus(422);

        $this->assertCount(1, $schedule->fresh()->items);
    }

    #[Test]
    public function another_customers_schedule_cannot_have_its_basket_rewritten(): void
    {
        $schedule = $this->dueSchedule();

        Sanctum::actingAs($this->customer('+201077665522'));

        $this->putJson("/api/v1/recurrences/{$schedule->id}/items", [
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 9]],
        ], $this->apiHeaders())->assertNotFound();

        $this->assertSame(2, $schedule->fresh()->items[0]['qty']);
    }

    #[Test]
    public function declining_skips_the_cycle_and_keeps_the_schedule(): void
    {
        $schedule = $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/decline", [], $this->apiHeaders())->assertOk();

        $this->assertSame('declined', $prompt->fresh()->answer);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame('active', $schedule->fresh()->status);
        $this->assertNotNull($schedule->fresh()->next_prompt_on);
    }

    #[Test]
    public function a_prompt_cannot_be_answered_twice(): void
    {
        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        $body = [
            'prompt_id' => $prompt->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ];

        $this->postJson('/api/v1/orders', $body, $this->apiHeaders())->assertCreated();

        // A second wizard run against a spent question must not buy the same
        // wash twice - nor may the prompt be re-opened by either answer.
        $this->postJson('/api/v1/orders', $body, $this->apiHeaders())->assertStatus(400);
        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders())->assertStatus(400);
        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/decline", [], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_customer_cannot_answer_someone_elses_prompt(): void
    {
        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        $stranger = $this->customer('+201077665544');
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders())->assertNotFound();
        $this->getJson('/api/v1/recurrences/prompts', $this->apiHeaders())->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_paused_schedule_is_not_asked(): void
    {
        $schedule = $this->dueSchedule();

        Sanctum::actingAs($this->customer);
        $this->putJson("/api/v1/recurrences/{$schedule->id}/pause", [], $this->apiHeaders())->assertOk();

        $this->artisan('orders:prompt-recurring')->assertSuccessful();

        $this->assertSame(0, RecurrencePrompt::count());
    }

    #[Test]
    public function resuming_moves_the_next_question_into_the_future(): void
    {
        $schedule = $this->dueSchedule();
        $schedule->update(['status' => 'paused', 'next_prompt_on' => now()->subMonths(2)->toDateString()]);

        Sanctum::actingAs($this->customer);
        $this->putJson("/api/v1/recurrences/{$schedule->id}/resume", [], $this->apiHeaders())->assertOk();

        // Without the re-anchor a long-paused schedule would come back due in the
        // past and fire the moment it resumed.
        $this->assertTrue($schedule->fresh()->next_prompt_on->isAfter(now()->subDay()));
        $this->assertSame('active', $schedule->fresh()->status);
    }

    #[Test]
    public function a_late_scheduler_keeps_the_schedule_on_its_weekday(): void
    {
        $monday = now()->startOfWeek();

        $schedule = app(RecurrenceService::class)->create($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ]);

        $schedule->update(['next_prompt_on' => $monday->toDateString()]);

        // Two days late.
        $this->artisan('orders:prompt-recurring', ['--date' => $monday->copy()->addDays(2)->toDateString()])
            ->assertSuccessful();

        // The prompt belongs to the cycle it was due for, and the schedule stays
        // on Mondays rather than drifting to Wednesdays.
        $this->assertSame($monday->toDateString(), RecurrencePrompt::firstOrFail()->prompted_for->toDateString());
        $this->assertSame(1, $schedule->fresh()->next_prompt_on->dayOfWeekIso);
    }

    #[Test]
    public function cancelling_a_schedule_stops_it_being_asked(): void
    {
        $schedule = $this->dueSchedule();

        Sanctum::actingAs($this->customer);
        $this->deleteJson("/api/v1/recurrences/{$schedule->id}", [], $this->apiHeaders())->assertOk();

        $this->artisan('orders:prompt-recurring')->assertSuccessful();

        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertNull($schedule->fresh()->next_prompt_on);
        $this->assertSame(0, RecurrencePrompt::count());
    }

    private function dueSchedule(): OrderRecurrence
    {
        $schedule = app(RecurrenceService::class)->create($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->address->id,
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ]);

        $schedule->update(['next_prompt_on' => now()->toDateString()]);

        return $schedule->fresh();
    }
}
