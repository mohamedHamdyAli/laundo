<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Order\Models\OrderRecurrence;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The repeat schedules screen.
 *
 * `recurrences` had an API from P6, a daily scheduled prompt, and a real
 * notification from P11 — and no screen at any point. Nobody could say how many
 * customers were on a schedule, whether anybody answered, or why one customer got
 * a message every week; and support could not stop one.
 *
 * The isolation tests matter most. A schedule carries no `laundry_id`, so the
 * tenant scope offers no protection at all here — only the permission does, and
 * if it is granted too widely one laundry reads another laundry's customers.
 */
class RecurrenceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('01099998888');
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
    }

    private function schedule(string $status = 'active', ?string $nextOn = null): OrderRecurrence
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return OrderRecurrence::create([
            'user_id' => $this->customer->id,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'frequency' => 'weekly',
            'day_of_week' => 2,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 3]],
            'next_prompt_on' => $status === 'active' ? ($nextOn ?? now()->addWeek()->toDateString()) : null,
            'status' => $status,
        ]);
    }

    // ------------------------------------------------------------- reaching it

    #[Test]
    public function a_super_admin_can_open_the_list_and_a_schedule(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->superAdmin());

        $this->get('/admin/recurrence')->assertOk();
        $this->get('/admin/recurrence/show/'.$schedule->id)->assertOk();
    }

    #[Test]
    public function a_laundry_owner_cannot_reach_it_at_all(): void
    {
        // Not a styling choice. A schedule belongs to a customer and names a
        // service; it has no laundry_id, so the tenant scope would not stop a
        // laundry owner from reading every customer on the platform.
        $schedule = $this->schedule();

        $this->actingAs($this->tenant['owner']);

        $this->get('/admin/recurrence')->assertForbidden();
        $this->get('/admin/recurrence/show/'.$schedule->id)->assertForbidden();
        $this->post('/admin/recurrence/pause/'.$schedule->id)->assertForbidden();
        $this->post('/admin/recurrence/cancel/'.$schedule->id)->assertForbidden();
    }

    #[Test]
    public function a_customer_cannot_reach_it(): void
    {
        $this->actingAs($this->customer);

        $this->get('/admin/recurrence')->assertForbidden();
    }

    // ---------------------------------------------------------------- the list

    #[Test]
    public function the_counters_split_the_schedules_by_state(): void
    {
        $this->schedule('active');
        $this->schedule('active');
        $this->schedule('paused');
        $this->schedule('cancelled');

        $this->actingAs($this->superAdmin());

        $response = $this->get('/admin/recurrence')->assertOk();

        $counts = $response->viewData('counts');

        $this->assertSame(2, $counts['active']);
        $this->assertSame(1, $counts['paused']);
        $this->assertSame(1, $counts['cancelled']);
    }

    #[Test]
    public function the_unanswered_counter_counts_prompts_that_were_sent_and_ignored(): void
    {
        $schedule = $this->schedule();

        // Sent and ignored — the figure that says the prompt is a nuisance.
        RecurrencePrompt::create([
            'recurrence_id' => $schedule->id,
            'prompted_for' => now()->subWeeks(2)->toDateString(),
            'prompted_at' => now()->subWeeks(2),
        ]);

        // Sent and answered.
        RecurrencePrompt::create([
            'recurrence_id' => $schedule->id,
            'prompted_for' => now()->subWeek()->toDateString(),
            'prompted_at' => now()->subWeek(),
            'answer' => 'declined',
            'answered_at' => now()->subWeek(),
        ]);

        // Created but never delivered. Not "ignored" — nobody was asked.
        RecurrencePrompt::create([
            'recurrence_id' => $schedule->id,
            'prompted_for' => now()->toDateString(),
        ]);

        $this->actingAs($this->superAdmin());

        $counts = $this->get('/admin/recurrence')->assertOk()->viewData('counts');

        $this->assertSame(1, $counts['unanswered']);
    }

    #[Test]
    public function the_status_filter_narrows_the_list(): void
    {
        $this->schedule('active');
        $this->schedule('paused');

        $this->actingAs($this->superAdmin());

        $paused = $this->get('/admin/recurrence?status=paused')->assertOk()->viewData('recurrences');

        $this->assertCount(1, $paused);
        $this->assertSame('paused', $paused->first()->status);
    }

    #[Test]
    public function the_list_counts_prompts_answers_and_orders_separately(): void
    {
        $schedule = $this->schedule();

        // Fixed dates, not random ones. `recurrence_prompts` is unique on
        // (recurrence_id, prompted_for), and three draws from random_int(1, 60)
        // collide about one run in twenty — which is a test that fails for a
        // reason that has nothing to do with what it is checking.
        foreach ([[1, 'confirmed'], [2, 'declined'], [3, null]] as [$daysAgo, $answer]) {
            RecurrencePrompt::create([
                'recurrence_id' => $schedule->id,
                'prompted_for' => now()->subDays($daysAgo)->toDateString(),
                'prompted_at' => now()->subDays($daysAgo),
                'answer' => $answer,
                'answered_at' => $answer === null ? null : now(),
            ]);
        }

        $this->actingAs($this->superAdmin());

        $row = $this->get('/admin/recurrence')->assertOk()->viewData('recurrences')->first();

        // Three asked, two answered, one of those a yes. "Answered" and "became an
        // order" are different questions and a single column would hide it.
        $this->assertSame(3, $row->prompts_count);
        $this->assertSame(2, $row->answered_prompts_count);
        $this->assertSame(1, $row->confirmed_prompts_count);
    }

    // --------------------------------------------------------------- the detail

    #[Test]
    public function the_answer_rate_is_null_when_nobody_has_been_asked(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->superAdmin());

        // Never asked and 0% are different claims, and a report that conflates
        // them accuses a customer of ignoring a message never sent.
        $this->assertNull(
            $this->get('/admin/recurrence/show/'.$schedule->id)->assertOk()->viewData('answerRate')
        );
    }

    #[Test]
    public function the_answer_rate_counts_only_prompts_that_were_actually_sent(): void
    {
        $schedule = $this->schedule();

        foreach ([true, true, false] as $i => $sent) {
            RecurrencePrompt::create([
                'recurrence_id' => $schedule->id,
                'prompted_for' => now()->subDays($i + 1)->toDateString(),
                'prompted_at' => $sent ? now()->subDays($i + 1) : null,
                'answer' => $i === 0 ? 'confirmed' : null,
                'answered_at' => $i === 0 ? now() : null,
            ]);
        }

        $this->actingAs($this->superAdmin());

        // Two sent, one answered. The undelivered third must not drag the rate
        // down — that would blame the customer for our own failure to send.
        $this->assertSame(
            50.0,
            $this->get('/admin/recurrence/show/'.$schedule->id)->assertOk()->viewData('answerRate')
        );
    }

    // ----------------------------------------------------------- intervening

    #[Test]
    public function pausing_a_schedule_stops_it_being_prompted(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/pause/'.$schedule->id)
            ->assertRedirect();

        $schedule->refresh();

        $this->assertSame('paused', $schedule->status);
        // `due` is what the daily command reads, so this is the real proof it
        // stopped rather than merely a changed label.
        $this->assertFalse(
            OrderRecurrence::due(now()->addYear())->where('id', $schedule->id)->exists()
        );
    }

    #[Test]
    public function resuming_puts_it_back_in_the_queue_with_a_fresh_date(): void
    {
        $schedule = $this->schedule('paused');

        $this->assertNull($schedule->next_prompt_on);

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/resume/'.$schedule->id)
            ->assertRedirect();

        $schedule->refresh();

        $this->assertSame('active', $schedule->status);
        // A resumed schedule must not inherit a date in the past, or it fires
        // immediately and the customer gets a prompt the moment support helps them.
        $this->assertNotNull($schedule->next_prompt_on);
        $this->assertTrue($schedule->next_prompt_on->greaterThanOrEqualTo(now()->startOfDay()));
    }

    #[Test]
    public function cancelling_clears_the_next_date_as_well_as_the_status(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/cancel/'.$schedule->id)
            ->assertRedirect();

        $schedule->refresh();

        $this->assertSame('cancelled', $schedule->status);
        // A stale date on a cancelled row is how a "stopped" schedule comes back.
        $this->assertNull($schedule->next_prompt_on);
    }

    #[Test]
    public function pausing_something_already_paused_is_refused_rather_than_silently_repeated(): void
    {
        $schedule = $this->schedule('paused');

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/pause/'.$schedule->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('paused', $schedule->refresh()->status);
    }

    #[Test]
    public function a_cancelled_schedule_cannot_be_cancelled_again(): void
    {
        $schedule = $this->schedule('cancelled');

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/cancel/'.$schedule->id)
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    public function resuming_something_active_is_refused(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->superAdmin())
            ->post('/admin/recurrence/resume/'.$schedule->id)
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ------------------------------------------------------------------ search

    #[Test]
    public function search_finds_a_schedule_by_the_customers_phone(): void
    {
        $this->schedule();

        $this->actingAs($this->superAdmin());

        $response = $this->getJson('/admin/recurrence/search?query=01099998888', [
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();

        $this->assertStringContainsString('01099998888', $response->json('table'));
    }

    #[Test]
    public function search_for_somebody_who_has_no_schedule_returns_an_empty_table(): void
    {
        $this->schedule();

        $this->actingAs($this->superAdmin());

        $response = $this->getJson('/admin/recurrence/search?query=01000000000', [
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();

        $this->assertStringNotContainsString('01099998888', $response->json('table'));
    }
}
