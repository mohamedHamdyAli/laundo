<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\RecurrenceService;
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

        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
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
    public function confirming_a_prompt_creates_the_order_at_todays_prices(): void
    {
        $this->dueSchedule();
        $this->artisan('orders:prompt-recurring')->assertSuccessful();
        $prompt = RecurrencePrompt::firstOrFail();

        Sanctum::actingAs($this->customer);

        $response = $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders());

        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($order->id, $prompt->fresh()->order_id);
        $this->assertSame('confirmed', $prompt->fresh()->answer);
        $this->assertSame(OrderRecurrence::firstOrFail()->id, $order->recurrence_id);

        // 2 x 17 = 34, priced when the order was created rather than when the
        // schedule was saved.
        $this->assertSame('34.00', $order->estimated_subtotal);
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

        $this->postJson("/api/v1/recurrences/prompts/{$prompt->id}/confirm", [], $this->apiHeaders())->assertCreated();
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

        $stranger = $this->customer('01077665544');
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
