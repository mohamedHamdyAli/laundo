<?php

namespace Tests\Feature\Api;

use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «تقديم شكوى», both ends of it.
 *
 * Two entry points and they are not the same shape: the driver app lists it in the
 * account screen — a general complaint — while the customer reaches it from an
 * order. `order_id` is optional for exactly that reason.
 *
 * Operations answers by phone, per the owner's decision, so there is no reply
 * thread. What is asserted hardest here is the consequence of that: the reference
 * and the status must reach the complainant, and the internal note must not.
 */
class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $driver;

    /** @var array<string, mixed> */
    private array $tenant;

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
        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->customer = $this->customer('+201055550001');
        $this->driver = $this->driverUser('+201066660001');
    }

    private function order(?User $for = null): Order
    {
        $owner = $for ?? $this->customer;
        $address = $this->addressFor($owner, $this->geo['zones'][0]);

        $order = app(OrderService::class)->place($owner, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'accepts_review_terms' => true,
        ]);

        $order->forceFill(['laundry_id' => $this->tenant['laundry']->id])->save();

        return $order->fresh();
    }

    // ------------------------------------------------------------- submitting

    #[Test]
    public function a_customer_can_complain_about_one_of_their_orders(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'damaged_item',
                'body' => 'القميص الأبيض رجع بقطع',
                'order_id' => $order->id,
            ])
            ->assertCreated();

        // The reference is the point of the reply: operations answers by phone and
        // that is what gets quoted on the call.
        $this->assertMatchesRegularExpression('/^CMP-[A-Z0-9]{8}$/', $response->json('data.reference'));
        $this->assertStringContainsString($response->json('data.reference'), $response->json('msg'));

        $complaint = Complaint::firstOrFail();

        $this->assertSame($this->customer->id, $complaint->user_id);
        $this->assertSame($order->id, $complaint->order_id);
        // Copied from the order — it decides which laundry this is counted against.
        $this->assertSame($this->tenant['laundry']->id, $complaint->laundry_id);
        $this->assertSame(ComplaintStatus::New->value, $complaint->status);
    }

    #[Test]
    public function a_driver_can_complain_about_nothing_in_particular(): void
    {
        // The driver app lists «تقديم شكوى» in the account screen, with no order
        // in sight. Requiring one would make the feature unreachable for them.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints', [
                'category' => 'app_problem',
                'body' => 'التطبيق بيقفل لوحده وأنا في الشارع',
            ])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->assertNull($complaint->order_id);
        $this->assertNull($complaint->laundry_id);
        $this->assertSame($this->driver->id, $complaint->user_id);
    }

    #[Test]
    public function a_driver_can_name_the_order_they_were_sent_on(): void
    {
        $order = $this->order();

        // The four legs already exist — placing the order generates them — so
        // this hands one to the driver rather than inventing a fifth.
        OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::PickupFromCustomer->value)
            ->update(['driver_id' => $this->driver->id, 'status' => TaskStatus::Completed->value]);

        // «مشكلة مع العميل» is about a specific doorstep or it is about nothing.
        // The check used to run through the complainant's *placed* orders, so a
        // driver quoting the job they had just been on was told it did not exist.
        // `customer_conduct`, not `driver_conduct`: the driver is complaining
        // *about* the customer. Both read the same in a hurry, which is why the
        // category the endpoint accepts is now decided by the token's role and
        // not by a field the caller sends — this test filed under the customer's
        // side of the platform for as long as nothing checked.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints', [
                'category' => 'customer_conduct',
                'body' => 'العميل مكانش موجود واتصلت عليه خمس مرات',
                'order_id' => $order->id,
            ])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->assertSame($order->id, $complaint->order_id);
        $this->assertSame($this->driver->id, $complaint->user_id);
        // Still copied from the order, never from the payload: it is what counts
        // the complaint against the right laundry.
        $this->assertSame($order->laundry_id, $complaint->laundry_id);
    }

    #[Test]
    public function the_side_a_complaint_is_filed_under_comes_from_the_token(): void
    {
        // The narrowing has to be decided by something the caller cannot set,
        // or it is decoration: a client that simply omits `audience` used to get
        // the whole vocabulary back, so a driver could file «الهدوم اتخربت» and
        // a customer could file «مشكلة مع العميل». The dashboard's audience
        // filter runs off the complainant's role, so the list and the badge
        // would then disagree about which side the complaint was on.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints', [
                'category' => 'damaged_item',
                'body' => 'a category that belongs to the other side',
                'audience' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'customer_conduct',
                'body' => 'a category that belongs to the other side',
                'audience' => 'driver',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertSame(0, Complaint::count());

        // And what each side may say still goes through untouched.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints', [
                'category' => 'customer_conduct',
                'body' => 'the customer was not at the door',
            ])
            ->assertCreated();
    }

    #[Test]
    public function a_driver_cannot_name_an_order_they_were_never_sent_on(): void
    {
        $order = $this->order();

        // No task, no standing. Widening the check to "any driver, any order"
        // would have handed every driver every order in the system.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints', [
                'category' => 'customer_conduct',
                'body' => 'an order I have never been near',
                'order_id' => $order->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, Complaint::count());
    }

    #[Test]
    public function somebody_elses_order_cannot_be_named(): void
    {
        $stranger = $this->customer('+201055550002');
        $theirOrder = $this->order($stranger);

        // Otherwise a complaint gets filed against a laundry over an order the
        // complainant has never seen.
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'late',
                'body' => 'not my order at all',
                'order_id' => $theirOrder->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, Complaint::count());
    }

    #[Test]
    public function the_dashboard_queue_can_be_narrowed_to_one_side_of_the_platform(): void
    {
        // Both apps file here now, and «مشكلة مع العميل» is not the same work as
        // «الهدوم اتخربت» — rarely the same person's morning either.
        $this->actingAs($this->customer)->postJson('/api/v1/complaints', [
            'category' => 'not_clean', 'body' => 'came back with the stain still on it',
        ])->assertCreated();

        $this->actingAs($this->driver)->postJson('/api/v1/complaints?audience=driver', [
            'category' => 'customer_conduct', 'body' => 'nobody answered the door',
        ])->assertCreated();

        $admin = $this->superAdmin();

        $everyone = $this->actingAs($admin)->get('/admin/complaint')->assertOk();
        $this->assertStringContainsString('stain still on it', $everyone->getContent());
        $this->assertStringContainsString('nobody answered the door', $everyone->getContent());

        $drivers = $this->actingAs($admin)->get('/admin/complaint?audience=driver&status=all')->assertOk();
        $this->assertStringContainsString('nobody answered the door', $drivers->getContent());
        $this->assertStringNotContainsString('stain still on it', $drivers->getContent());

        $customers = $this->actingAs($admin)->get('/admin/complaint?audience=customer&status=all')->assertOk();
        $this->assertStringContainsString('stain still on it', $customers->getContent());
        $this->assertStringNotContainsString('nobody answered the door', $customers->getContent());
    }

    #[Test]
    public function the_queue_survives_a_search_with_the_filter_on(): void
    {
        // The filter has to ride the AJAX search too, or typing a word silently
        // widens the queue back to everybody — the trap `extraParams` exists for.
        $this->actingAs($this->driver)->postJson('/api/v1/complaints?audience=driver', [
            'category' => 'customer_conduct', 'body' => 'nobody answered the door',
        ])->assertCreated();

        $this->actingAs($this->customer)->postJson('/api/v1/complaints', [
            'category' => 'not_clean', 'body' => 'nobody cleaned it properly',
        ])->assertCreated();

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/complaint/search?query=nobody&audience=driver&status=all', [
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk();

        $table = $response->json('table');

        $this->assertStringContainsString('nobody answered the door', $table);
        $this->assertStringNotContainsString('nobody cleaned it properly', $table);
    }

    #[Test]
    public function every_reference_is_unique(): void
    {
        $this->actingAs($this->customer);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/complaints', [
                'category' => 'other',
                'body' => 'complaint number '.$i,
            ])->assertCreated();
        }

        $this->assertSame(5, Complaint::distinct('reference')->count('reference'));
    }

    #[Test]
    public function the_reference_is_not_derived_from_the_row_id(): void
    {
        // A sequential reference tells a caller how many complaints exist and is
        // trivially guessable by anyone wanting to quote somebody else's.
        //
        // Asserting the reference merely does not *contain* the id was the first
        // version of this test, and it failed roughly one run in eight: an
        // eight-character random tail contains the digit "1" by coincidence most
        // of the time. That measured luck, not the property. What actually matters
        // is that the reference is not a function of the id, so the test builds
        // several and checks none of them is.
        $this->actingAs($this->customer);

        $references = [];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/complaints', [
                'category' => 'other',
                'body' => 'complaint text '.$i,
            ])->assertCreated();
        }

        foreach (Complaint::orderBy('id')->get() as $complaint) {
            $references[] = $complaint->reference;

            // The two shapes a derived reference would take.
            $this->assertNotSame('CMP-'.$complaint->id, $complaint->reference);
            $this->assertNotSame(
                'CMP-'.str_pad((string) $complaint->id, 8, '0', STR_PAD_LEFT),
                $complaint->reference
            );
        }

        // And the tails are genuinely different from one another rather than
        // marching in step.
        $tails = array_map(fn ($r) => substr($r, 4), $references);
        $this->assertCount(5, array_unique($tails));
    }

    // ----------------------------------------------------------- what they see

    #[Test]
    public function a_complainant_reads_their_own_complaints_and_nobody_elses(): void
    {
        $stranger = $this->customer('+201055550002');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'my own complaint'])
            ->assertCreated();

        $this->actingAs($stranger)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'their own complaint'])
            ->assertCreated();

        $mine = $this->actingAs($this->customer)->getJson('/api/v1/complaints')->assertOk()->json('data');

        $this->assertCount(1, $mine);
        $this->assertSame('my own complaint', $mine[0]['body']);
    }

    #[Test]
    public function the_internal_note_never_reaches_the_complainant(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post('/admin/complaint/transition/'.$complaint->id, [
                'status' => ComplaintStatus::InProgress->value,
                'note' => 'SECRET: customer was rude on the phone',
            ])
            ->assertRedirect();

        // The note is where operations writes what was actually said. It is not
        // addressed to the complainant and must not appear in their responses.
        foreach ([
            $this->actingAs($this->customer)->getJson('/api/v1/complaints')->assertOk(),
            $this->actingAs($this->customer)->getJson('/api/v1/complaints/'.$complaint->id)->assertOk(),
        ] as $response) {
            $response->assertDontSee('SECRET');
        }
    }

    #[Test]
    public function the_complainant_can_see_the_status_move(): void
    {
        // No reply thread, so the status is the only honest signal that anything
        // happened. Without it a complaint is a black hole.
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->assertTrue(
            $this->actingAs($this->customer)
                ->getJson('/api/v1/complaints/'.$complaint->id)->assertOk()->json('data.is_open')
        );

        $this->actingAs($this->superAdmin())
            ->post('/admin/complaint/transition/'.$complaint->id, ['status' => ComplaintStatus::Resolved->value])
            ->assertRedirect();

        $after = $this->actingAs($this->customer)
            ->getJson('/api/v1/complaints/'.$complaint->id)->assertOk();

        $this->assertFalse($after->json('data.is_open'));
        $this->assertSame(ComplaintStatus::Resolved->value, $after->json('data.status'));
        $this->assertNotSame('', trim((string) $after->json('data.status_label')));
    }

    #[Test]
    public function the_categories_are_served_so_two_apps_do_not_keep_their_own_list(): void
    {
        $data = $this->getJson('/api/v1/complaint-categories')->assertOk()->json('data');

        // Everything, when nobody says which app is asking: guessing from an
        // absent token would serve one app the other's words.
        $this->assertCount(9, $data);
        $this->assertSame('damaged_item', $data[0]['value']);
        // A hint for the client, not a rule.
        $this->assertTrue($data[0]['needs_order']);
    }

    #[Test]
    public function the_two_apps_are_offered_different_words(): void
    {
        $customer = collect($this->getJson('/api/v1/complaint-categories?audience=customer')
            ->assertOk()->json('data'))->pluck('value');

        $driver = collect($this->getJson('/api/v1/complaint-categories?audience=driver')
            ->assertOk()->json('data'))->pluck('value');

        // «هدوم اتخربت» describes the laundry's work on somebody's clothes, and
        // offering it on a driver's screen is offering the wrong words to
        // somebody with a real problem.
        $this->assertTrue($customer->contains('damaged_item'));
        $this->assertFalse($driver->contains('damaged_item'));
        $this->assertFalse($driver->contains('not_clean'));

        // Each side complains about the other, and only one direction existed.
        $this->assertTrue($customer->contains('driver_conduct'));
        $this->assertFalse($customer->contains('customer_conduct'));
        $this->assertTrue($driver->contains('customer_conduct'));
        $this->assertFalse($driver->contains('driver_conduct'));

        // Late, payment, the app and «أخرى» happen to both, so they are shared
        // rather than duplicated per audience.
        foreach (['late', 'payment', 'app_problem', 'other'] as $shared) {
            $this->assertTrue($customer->contains($shared), "{$shared} is the customer's too");
            $this->assertTrue($driver->contains($shared), "{$shared} is the driver's too");
        }
    }

    #[Test]
    public function the_contact_us_message_box_files_a_support_request(): void
    {
        // «تواصل معنا» is a message box with a send button and no category
        // chooser — the app sets the category itself. It used to confirm without
        // sending anything at all.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints?audience=driver', [
                'category' => 'support_request',
                'body' => 'عايز أعرف المكافأة بتتحسب إزاي',
            ])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->assertSame('support_request', $complaint->category);
        // The reference is the point: it is what the courier quotes on the call
        // back, and what stops the message being a black hole.
        $this->assertNotNull($complaint->reference);
        $this->assertNull($complaint->order_id, 'a general question names no order');
    }

    #[Test]
    public function support_request_is_accepted_but_never_offered_in_the_list(): void
    {
        // What a person may choose and what the endpoint may be sent are two
        // different sets the moment one category is set by a screen rather than
        // picked from a dropdown. «طلب دعم» in the complaint-type list would be
        // a screen's name offered as a kind of problem.
        foreach (['', '?audience=customer', '?audience=driver'] as $query) {
            $offered = collect($this->getJson('/api/v1/complaint-categories'.$query)
                ->assertOk()->json('data'))->pluck('value');

            $this->assertFalse(
                $offered->contains('support_request'),
                "support_request must not be listed for '{$query}'"
            );
        }

        // Both apps draw the screen, so both may post it.
        foreach ([$this->customer, $this->driver] as $sender) {
            Complaint::query()->delete();

            $this->actingAs($sender)
                ->postJson('/api/v1/complaints', [
                    'category' => 'support_request',
                    'body' => 'a question from the contact screen',
                ])
                ->assertCreated();
        }
    }

    #[Test]
    public function support_requests_are_countable_apart_from_everything_else(): void
    {
        // The reason it is not folded into «أخرى»: «كام واحد كتبلنا من تواصل
        // معنا» is a question somebody will ask, and Other is where an answer
        // goes to stop being countable.
        $this->actingAs($this->customer)->postJson('/api/v1/complaints', [
            'category' => 'support_request', 'body' => 'a question',
        ])->assertCreated();

        $this->actingAs($this->customer)->postJson('/api/v1/complaints', [
            'category' => 'other', 'body' => 'something else entirely',
        ])->assertCreated();

        $this->assertSame(1, Complaint::where('category', 'support_request')->count());
        $this->assertSame(1, Complaint::where('category', 'other')->count());
    }

    #[Test]
    public function a_category_the_audience_was_never_offered_is_refused(): void
    {
        // Otherwise the narrowing is decoration: a client could draw the driver's
        // list and still file «الهدوم اتخربت» against a laundry.
        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints?audience=driver', [
                'category' => 'damaged_item',
                'body' => 'filed from the wrong list entirely',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertSame(0, Complaint::count());
    }

    #[Test]
    public function a_driver_can_finally_complain_about_a_customer(): void
    {
        // The case that had nowhere to go: every other category describes
        // something done *to* a customer, so a driver with a real problem at a
        // doorstep had only «أخرى», where it stops being countable.
        $order = $this->order();

        OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::PickupFromCustomer->value)
            ->update(['driver_id' => $this->driver->id, 'status' => TaskStatus::Completed->value]);

        $this->actingAs($this->driver)
            ->postJson('/api/v1/complaints?audience=driver', [
                'category' => 'customer_conduct',
                'body' => 'محدش رد عليا وقفت نص ساعة قدام العمارة',
                'order_id' => $order->id,
            ])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->assertSame('customer_conduct', $complaint->category);
        $this->assertSame($order->id, $complaint->order_id);
    }

    // ----------------------------------------------------------------- validation

    #[Test]
    public function a_complaint_needs_a_category_and_some_words(): void
    {
        $this->actingAs($this->customer);

        $this->postJson('/api/v1/complaints', ['body' => 'no category given'])->assertStatus(422);
        $this->postJson('/api/v1/complaints', ['category' => 'other'])->assertStatus(422);
        // Five characters minimum: "x" is not a complaint anybody can act on.
        $this->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'x'])->assertStatus(422);
    }

    #[Test]
    public function an_invented_category_is_refused(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'invented', 'body' => 'a complaint'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_guest_cannot_complain(): void
    {
        $this->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------ the dashboard

    #[Test]
    public function only_the_super_admin_reaches_the_queue(): void
    {
        // The whole protection. `Complaint` deliberately does not use
        // BelongsToLaundry, so if this permission ever reaches a laundry role,
        // every customer's complaint opens to them.
        $this->actingAs($this->superAdmin())->get('/admin/complaint')->assertOk();
        $this->actingAs($this->tenant['owner'])->get('/admin/complaint')->assertForbidden();
        $this->actingAs($this->customer)->get('/admin/complaint')->assertForbidden();
    }

    #[Test]
    public function a_note_appends_rather_than_replacing(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->actingAs($this->superAdmin());

        $this->post('/admin/complaint/note/'.$complaint->id, ['note' => 'called, no answer'])
            ->assertRedirect();
        $this->post('/admin/complaint/note/'.$complaint->id, ['note' => 'called again, spoke to them'])
            ->assertRedirect();

        $note = $complaint->fresh()->internal_note;

        // Two people work the same complaint over a week; the second overwriting
        // the first is how "we already told them that" gets lost.
        $this->assertStringContainsString('called, no answer', $note);
        $this->assertStringContainsString('called again, spoke to them', $note);
    }

    #[Test]
    public function a_complaint_cannot_skip_straight_from_resolved_to_resolved(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->actingAs($this->superAdmin());

        $this->post('/admin/complaint/transition/'.$complaint->id, ['status' => ComplaintStatus::Resolved->value])
            ->assertRedirect()->assertSessionHas('success');

        $handledAt = $complaint->fresh()->handled_at;

        // Marking it resolved twice would overwrite handled_at and lose how long
        // it actually took — the one figure this screen exists to produce.
        $this->post('/admin/complaint/transition/'.$complaint->id, ['status' => ComplaintStatus::Resolved->value])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertEquals($handledAt, $complaint->fresh()->handled_at);
    }

    #[Test]
    public function a_resolved_complaint_can_be_reopened(): void
    {
        // A customer calling back about the same thing is common, and forcing a
        // second complaint loses the history.
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'a complaint'])
            ->assertCreated();

        $complaint = Complaint::firstOrFail();

        $this->actingAs($this->superAdmin());

        $this->post('/admin/complaint/transition/'.$complaint->id, ['status' => ComplaintStatus::Resolved->value]);
        $this->post('/admin/complaint/transition/'.$complaint->id, ['status' => ComplaintStatus::InProgress->value])
            ->assertRedirect()->assertSessionHas('success');

        $complaint->refresh();

        $this->assertSame(ComplaintStatus::InProgress->value, $complaint->status);
        // Reopened means unfinished, so the completion stamp has to go.
        $this->assertNull($complaint->handled_at);
    }

    #[Test]
    public function the_queue_counts_the_ones_left_waiting_over_a_day(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'old one'])
            ->assertCreated();

        Complaint::firstOrFail()->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', ['category' => 'other', 'body' => 'fresh one'])
            ->assertCreated();

        $counts = $this->actingAs($this->superAdmin())
            ->get('/admin/complaint')->assertOk()->viewData('counts');

        $this->assertSame(2, $counts['new']);
        // A total never says the queue is not being worked. This does.
        $this->assertSame(1, $counts['stale']);
    }

    #[Test]
    public function low_ratings_with_a_comment_appear_in_the_same_queue(): void
    {
        // The rating form's own placeholder is «اكتب ملاحظاتك أو شكواك». Showing
        // these somewhere else means operations works two lists and believes each
        // is the whole picture.
        $order = $this->order();

        OrderRating::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'laundry_id' => $this->tenant['laundry']->id,
            'overall' => 1,
            'comment' => 'الطلب وصل متأخر جداً',
        ]);

        $response = $this->actingAs($this->superAdmin())->get('/admin/complaint')->assertOk();

        $this->assertSame(1, $response->viewData('counts')['from_ratings']);
        $this->assertCount(1, $response->viewData('ratingComplaints'));
    }

    #[Test]
    public function a_low_rating_with_no_comment_is_not_treated_as_a_complaint(): void
    {
        $order = $this->order();

        OrderRating::withoutGlobalScopes()->create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'laundry_id' => $this->tenant['laundry']->id,
            'overall' => 1,
        ]);

        // A bare score is a number. Only a score with words is something somebody
        // can act on, and only those belong in a work queue.
        $this->assertSame(
            0,
            $this->actingAs($this->superAdmin())
                ->get('/admin/complaint')->assertOk()->viewData('counts')['from_ratings']
        );
    }
}
