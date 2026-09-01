<?php

namespace Tests\Feature\Api;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Services\OrderService;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Six holes the Figma sweep found between the designed screens and the API.
 *
 * Each one is a control the design has drawn since the first version with nothing
 * behind it — a button that opens on an empty page, a fee with no line, a payment
 * method the last step refuses. None of them failed loudly, which is why they
 * survived nine phases.
 */
class FigmaGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

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
        $this->tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');
        $this->customer = $this->customer('01055550001');
    }

    private function order(?string $method = null): Order
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        return app(OrderService::class)->place($this->customer, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
            'payment_method' => $method,
            'accepts_review_terms' => true,
        ]);
    }

    // ------------------------------------------------- 9 · «انستا باي» at checkout

    #[Test]
    public function instapay_survives_the_last_step(): void
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        // The payment screen offers «انستا باي», the quote priced it and the pay
        // endpoint accepted it — and the create-order rule listed three methods
        // by hand, so the order itself was refused after all of that.
        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders', [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $address->id,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
                'payment_method' => 'instapay',
                'accepts_review_terms' => true,
            ])
            ->assertSuccessful();

        $this->assertSame('instapay', Order::latest('id')->value('payment_method'));
    }

    #[Test]
    public function a_method_that_is_not_a_method_is_still_refused(): void
    {
        $address = $this->addressFor($this->customer, $this->geo['zones'][0]);

        $this->actingAs($this->customer)
            ->postJson('/api/v1/orders', [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $address->id,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
                'payment_method' => 'bitcoin',
                'accepts_review_terms' => true,
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------- 10 · the surcharge on the order

    #[Test]
    public function the_cash_fee_has_a_line_on_the_order_too(): void
    {
        Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => '15']);
        Cache::flush();

        $order = $this->order('cash');

        $pricing = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->json('data.pricing');

        // It was in the quote and nowhere else, so a customer looking at the
        // order they placed saw a total carrying a fee they could not check.
        $this->assertEquals(15, $pricing['cash_surcharge']);
    }

    #[Test]
    public function a_card_order_shows_a_zero_rather_than_nothing(): void
    {
        Setting::updateOrCreate(['key' => 'Cash_Surcharge'], ['value' => '15']);
        Cache::flush();

        $order = $this->order('card');

        $this->assertEquals(
            0,
            $this->actingAs($this->customer)
                ->getJson('/api/v1/orders/'.$order->id)
                ->json('data.pricing.cash_surcharge')
        );
    }

    // ------------------------------------------------ 3 · «إظهار رمز الاستلام»

    #[Test]
    public function the_customer_can_finally_show_their_qr(): void
    {
        $order = $this->order();

        $qr = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->json('data.qr');

        // The button is on three screens and the token never left the server.
        $this->assertSame($order->qr_token, $qr);
    }

    #[Test]
    public function it_is_not_another_customers_qr(): void
    {
        $order = $this->order();
        $stranger = $this->customer('01055550002');

        // The order lookup is through the customer's own orders, so the token is
        // protected by the same boundary as everything else on the order.
        $this->actingAs($stranger)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertNotFound();
    }

    // --------------------------------------------- 2 · «مندوب الاستلام ★ 4.9»

    private function assign(Order $order, User $driver, TaskStatus $status = TaskStatus::Assigned): void
    {
        $order->tasks()->orderBy('sequence')->firstOrFail()
            ->forceFill(['driver_id' => $driver->id, 'status' => $status->value])->save();
    }

    #[Test]
    public function the_tracking_screen_names_the_driver(): void
    {
        $order = $this->order();
        $driver = $this->driverUser('01066660001');
        $this->assign($order, $driver);

        $card = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->assertOk()
            ->json('data.driver');

        // A customer waiting at home could see that somebody was on the way and
        // not who. The endpoint returned no driver at all.
        $this->assertSame($driver->name, $card['name']);
        $this->assertNotNull($card['role']);
    }

    #[Test]
    public function an_unassigned_order_has_no_driver_card(): void
    {
        $order = $this->order();

        $this->assertNull(
            $this->actingAs($this->customer)
                ->getJson('/api/v1/orders/'.$order->id.'/track')
                ->json('data.driver')
        );
    }

    #[Test]
    public function the_star_is_what_customers_said_about_delivery(): void
    {
        $driver = $this->driverUser('01066660001');

        // Two finished orders this driver carried, rated 5 and 4 on delivery but
        // 1 on the wash. Averaging the aspects together would mark the driver
        // down for a badly ironed shirt — which is exactly why the columns are
        // separate.
        foreach ([[5, 1], [4, 1]] as [$delivery, $quality]) {
            $order = $this->order();
            $this->assign($order, $driver, TaskStatus::Completed);

            OrderRating::create([
                'order_id' => $order->id,
                'user_id' => $this->customer->id,
                'laundry_id' => $this->tenant['laundry']->id,
                'overall' => 3,
                'service_quality' => $quality,
                'delivery' => $delivery,
            ]);
        }

        $live = $this->order();
        $this->assign($live, $driver);

        $card = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$live->id.'/track')
            ->json('data.driver');

        $this->assertEquals(4.5, $card['rating']);
    }

    #[Test]
    public function an_unrated_driver_is_null_and_not_zero(): void
    {
        $order = $this->order();
        $driver = $this->driverUser('01066660001');
        $this->assign($order, $driver);

        // A new driver shown as 0.0 reads as a bad driver rather than an unrated
        // one, and the design draws stars.
        $this->assertNull(
            $this->actingAs($this->customer)
                ->getJson('/api/v1/orders/'.$order->id.'/track')
                ->json('data.driver.rating')
        );
    }

    #[Test]
    public function the_drivers_phone_number_is_not_handed_out(): void
    {
        $order = $this->order();
        $driver = $this->driverUser('01066660001');
        $this->assign($order, $driver);

        $card = $this->actingAs($this->customer)
            ->getJson('/api/v1/orders/'.$order->id.'/track')
            ->json('data.driver');

        // Handing a driver's personal mobile to every customer is a policy
        // decision, not a field. The design shows no call button.
        $this->assertArrayNotHasKey('phone', $card);
    }

    // ------------------------------------------------------ 6 · «المرفقات»

    #[Test]
    public function a_complaint_can_carry_photographs(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'not_clean',
                'body' => 'The stain on the white shirt is still there.',
                'photos' => [
                    UploadedFile::fake()->image('stain.jpg'),
                    UploadedFile::fake()->image('collar.jpg'),
                ],
            ])
            ->assertSuccessful();

        // For the complaints this exists for, the photograph is the evidence.
        $complaint = Complaint::latest('id')->firstOrFail();
        $this->assertCount(2, $complaint->attachments);
    }

    #[Test]
    public function the_photographs_come_back_with_the_complaint(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'not_clean',
                'body' => 'The stain on the white shirt is still there.',
                'photos' => [UploadedFile::fake()->image('stain.jpg')],
            ])->assertSuccessful();

        $complaint = Complaint::latest('id')->firstOrFail();

        $data = $this->actingAs($this->customer)
            ->getJson('/api/v1/complaints/'.$complaint->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['attachments']);
        // Still never the internal note.
        $this->assertArrayNotHasKey('internal_note', $data);
    }

    #[Test]
    public function a_complaint_with_no_photographs_is_still_a_complaint(): void
    {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'not_clean',
                'body' => 'The driver was two hours late and nobody called.',
            ])
            ->assertSuccessful();

        $this->assertCount(0, Complaint::latest('id')->firstOrFail()->attachments);
    }

    #[Test]
    public function a_pdf_is_not_a_photograph(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'not_clean',
                'body' => 'The stain on the white shirt is still there.',
                'photos' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function operations_sees_the_photographs(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer)
            ->postJson('/api/v1/complaints', [
                'category' => 'not_clean',
                'body' => 'The stain on the white shirt is still there.',
                'photos' => [UploadedFile::fake()->image('stain.jpg')],
            ])->assertSuccessful();

        $complaint = Complaint::latest('id')->firstOrFail();

        // An attachment nobody can see is worse than one that was never accepted:
        // the customer believes they have shown somebody the stain.
        $this->actingAs($this->superAdmin())
            ->get('/admin/complaint/show/'.$complaint->id)
            ->assertOk()
            ->assertSee($complaint->attachments->first()->url());
    }

    // ------------------------------------------------- 7 · «مستندات المركبة»

    #[Test]
    public function the_driver_can_see_the_documents_on_file(): void
    {
        $driver = $this->driverUser('01066660001');
        $driver->profile()->update([
            'license_image' => 'images/drivers/licence.jpg',
            'vehicle_registration_image' => 'images/drivers/registration.jpg',
        ]);

        $documents = $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/driver/profile')
            ->assertOk()
            ->json('data.documents');

        // The columns have existed since P5 and the payload never returned them,
        // so «مستندات المركبة» opened on an empty page.
        $this->assertCount(3, $documents);
        $this->assertNotNull(collect($documents)->firstWhere('key', 'license')['url']);
        $this->assertNull(collect($documents)->firstWhere('key', 'national_id')['url']);
    }

    #[Test]
    public function documents_stay_read_only(): void
    {
        $driver = $this->driverUser('01066660001');

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/profile', [
                'name' => 'New Name',
                'license_image' => 'anything',
                'license_number' => 'FORGED-1',
            ])
            ->assertSuccessful();

        // A verified record a driver can edit is not a verified record. Only the
        // three fields they own are writable, and the rest are dashboard work.
        $this->assertNotSame('FORGED-1', $driver->fresh()->profile?->license_number);
    }
}
