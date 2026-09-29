<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\User\Models\User;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A laundry's services change only when the platform approves.
 *
 * «لو طلب انو يقفل او يفتح سيرفس يجي طلب موافقه للسوبر ادمن وميقدرش يستقبل
 * طلبات ف السيرفس دي او تتشال منو غير بعد الموافقه». What is asserted hardest is
 * that asking changes nothing the assigner reads — in either direction — until
 * somebody says yes.
 */
class LaundryServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    /** @var array<string, mixed> */
    private array $tenant;

    private Service $ironing;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->cover($this->tenant['laundry'], $this->geo['zones'][0]->id, $this->catalog['service']->id);

        // A second service the laundry does not offer yet.
        $this->ironing = Service::create([
            'name' => json_encode(['en' => 'Iron Only', 'ar' => 'كي فقط'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item', 'sort_order' => 3, 'status' => 'active',
        ]);
        ItemPrice::create([
            'item_id' => $this->catalog['items'][0]->id, 'service_id' => $this->ironing->id, 'price' => 9,
        ]);

        $this->grant('laundry_owner', ['laundry_service.view', 'laundry_service.update']);
    }

    private function owner(): User
    {
        return $this->tenant['owner']->fresh();
    }

    /** Service ids the laundry offers right now — what the assigner reads. */
    private function offered(): array
    {
        return LaundryService::withoutGlobalScopes()
            ->where('laundry_id', $this->tenant['laundry']->id)->where('status', 'active')
            ->pluck('service_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function ownerSaves(array $serviceIds)
    {
        return $this->actingAs($this->owner())->put(route('admin.laundry_service.update'), ['services' => $serviceIds]);
    }

    private function pending(): Collection
    {
        return LaundryServiceRequest::withoutGlobalScopes()->where('status', 'pending')->get();
    }

    private function place(Service $service): Order
    {
        $customer = User::where('phone', '+201099880011')->first() ?? $this->customer('+201099880011');
        $address = $this->addressFor($customer, $this->geo['zones'][0]);

        // As the customer: placed while a laundry user is the actor, the Order
        // tenant hook would stamp that laundry on the row whatever the assigner
        // chose — which is the hook doing its job, not the assigner's answer.
        $this->actingAs($customer);

        return app(OrderService::class)->place($customer, [
            'service_id' => $service->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);
    }

    // ------------------------------------------------------------ asking

    #[Test]
    public function asking_to_open_a_service_changes_nothing_until_approved(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id])->assertRedirect();

        // Still only the one it had — and an order in the new service is not
        // handed to it.
        $this->assertSame([$this->catalog['service']->id], $this->offered());
        $this->assertNull($this->place($this->ironing)->laundry_id);

        $request = $this->pending()->sole();
        $this->assertSame('open', $request->action);
        $this->assertSame($this->ironing->id, $request->service_id);
    }

    #[Test]
    public function asking_to_close_a_service_keeps_it_running_until_approved(): void
    {
        $this->ownerSaves([])->assertRedirect();

        $this->assertSame([$this->catalog['service']->id], $this->offered());
        $this->assertSame($this->tenant['laundry']->id, $this->place($this->catalog['service'])->laundry_id);
        $this->assertSame('close', $this->pending()->sole()->action);
    }

    #[Test]
    public function asking_again_replaces_the_question_and_changing_your_mind_withdraws_it(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        // Same ask again: still one pending request, not two.
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        $this->assertCount(1, $this->pending());

        // Back to how things stand: nothing left to ask.
        $this->ownerSaves([$this->catalog['service']->id]);
        $this->assertCount(0, $this->pending());
        $this->assertSame(1, LaundryServiceRequest::withoutGlobalScopes()->where('status', 'superseded')->count());
    }

    #[Test]
    public function the_reviewers_are_told(): void
    {
        $admin = $this->superAdmin();

        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());
        // Nothing to the laundry itself for its own ask.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->tenant['owner']->id)->count());
    }

    #[Test]
    public function the_sidebar_counts_what_waits(): void
    {
        $this->ownerSaves([$this->ironing->id]);

        $this->actingAs($this->superAdmin());
        $this->assertSame(2, MenuBadges::for('laundry_service_request'));
    }

    // ------------------------------------------------------------ deciding

    #[Test]
    public function approving_an_open_starts_the_orders_and_tells_the_laundry(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        $request = $this->pending()->sole();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_service_request.approve', $request->id))
            ->assertSessionHas('success');

        $this->assertSame(
            collect([$this->catalog['service']->id, $this->ironing->id])->sort()->values()->all(),
            $this->offered()
        );
        $this->assertSame('approved', $request->fresh()->status);
        // Told before any order arrives (an order notifies the laundry too).
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->tenant['owner']->id)->count());
        $this->assertSame($this->tenant['laundry']->id, $this->place($this->ironing)->laundry_id);
    }

    #[Test]
    public function approving_a_close_stops_new_orders(): void
    {
        $this->ownerSaves([]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_service_request.approve', $this->pending()->sole()->id));

        $this->assertSame([], $this->offered());
        $this->assertNull($this->place($this->catalog['service'])->laundry_id);
    }

    #[Test]
    public function a_refusal_needs_a_reason_and_the_laundry_reads_it(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        $request = $this->pending()->sole();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_service_request.reject', $request->id), ['note' => ''])
            ->assertSessionHasErrors('note');
        $this->assertTrue($request->fresh()->isPending());

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_service_request.reject', $request->id), ['note' => 'No iron press on the site yet'])
            ->assertSessionHas('success');

        $this->assertSame([$this->catalog['service']->id], $this->offered());

        $this->actingAs($this->owner())
            ->get(route('admin.laundry_service.index'))
            ->assertOk()
            ->assertSee('No iron press on the site yet');
    }

    #[Test]
    public function a_decided_request_cannot_be_decided_again(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        $request = $this->pending()->sole();

        $this->actingAs($this->superAdmin())->post(route('admin.laundry_service_request.approve', $request->id));
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry_service_request.reject', $request->id), ['note' => 'late'])
            ->assertSessionHas('error');

        $this->assertSame('approved', $request->fresh()->status);
    }

    // ------------------------------------------------------------ who decides

    #[Test]
    public function a_laundry_cannot_approve_its_own_request(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);
        $request = $this->pending()->sole();

        // Without the permission: the route refuses.
        $this->actingAs($this->owner())
            ->post(route('admin.laundry_service_request.approve', $request->id))
            ->assertForbidden();

        // Even if somebody grants it to a laundry role by mistake, a laundry is
        // never the one to review.
        $this->grant('laundry_owner', ['laundry_service.view', 'laundry_service.update', 'laundry_service_request.view', 'laundry_service_request.update']);
        $this->actingAs($this->owner())
            ->post(route('admin.laundry_service_request.approve', $request->id))
            ->assertSessionHas('error');

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame([$this->catalog['service']->id], $this->offered());
    }

    #[Test]
    public function the_platform_editing_directly_is_the_approval(): void
    {
        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.laundry_service.update'), [
                'laundry_id' => $this->tenant['laundry']->id,
                'services' => [$this->ironing->id],
            ])->assertRedirect();

        // Written at once, and the laundry's pending ask is settled.
        $this->assertSame([$this->ironing->id], $this->offered());
        $this->assertCount(0, $this->pending());
    }

    #[Test]
    public function a_reviewer_with_the_permission_but_no_laundry_may_decide(): void
    {
        $this->grant('admin', ['laundry_service_request.view', 'laundry_service_request.update']);
        $reviewer = User::create([
            'name' => 'Reviewer', 'email' => 'reviewer@test.local', 'phone' => '+201000000088',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $this->ownerSaves([$this->catalog['service']->id, $this->ironing->id]);

        // Told about it, too.
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $reviewer->id)->count());

        $this->actingAs($reviewer)
            ->get(route('admin.laundry_service_request.index'))
            ->assertOk()
            ->assertSee('Iron Only');

        $this->actingAs($reviewer)
            ->post(route('admin.laundry_service_request.approve', $this->pending()->sole()->id))
            ->assertSessionHas('success');
    }

    // ------------------------------------------------------------ applying

    #[Test]
    public function an_application_names_its_services_and_approval_brings_them(): void
    {
        $this->post(route('laundry.register.store'), [
            'name' => ['en' => 'Applicant Laundry', 'ar' => 'مغسلة المتقدم'],
            'phone' => '+201066660001',
            'email' => 'hello@applicant.test',
            'city_id' => $this->geo['city']->id,
            'lat' => 30.0444, 'lng' => 31.2357,
            'owner_name' => 'Applicant Owner', 'owner_email' => 'owner@applicant.test',
            'owner_phone' => '+201066660002',
            'owner_password' => 'a-good-password', 'owner_password_confirmation' => 'a-good-password',
            'services' => [$this->ironing->id],
            'accepts_terms' => '1',
        ])->assertRedirect(route('laundry.applied'));

        $laundry = Laundry::withoutGlobalScopes()->where('phone', '+201066660001')->firstOrFail();

        $this->assertSame(
            [$this->ironing->id],
            LaundryService::withoutGlobalScopes()->where('laundry_id', $laundry->id)->pluck('service_id')->map(fn ($id) => (int) $id)->all()
        );

        // Shown to whoever decides the application.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry.pending'))
            ->assertOk()
            ->assertSee('Iron Only');
    }

    #[Test]
    public function an_application_with_no_service_is_refused(): void
    {
        $this->post(route('laundry.register.store'), [
            'name' => ['en' => 'Applicant Laundry'],
            'phone' => '+201066660001',
            'city_id' => $this->geo['city']->id,
            'lat' => 30.0444, 'lng' => 31.2357,
            'owner_name' => 'Applicant Owner', 'owner_email' => 'owner@applicant.test',
            'owner_phone' => '+201066660002',
            'owner_password' => 'a-good-password', 'owner_password_confirmation' => 'a-good-password',
            'accepts_terms' => '1',
        ])->assertSessionHasErrors('services');

        $this->assertSame(1, Laundry::withoutGlobalScopes()->count());
    }
}
