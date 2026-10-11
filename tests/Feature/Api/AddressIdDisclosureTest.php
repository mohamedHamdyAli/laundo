<?php

namespace Tests\Feature\Api;

use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An address id that is nobody's must answer exactly like one that is somebody
 * else's (2026-10-11).
 *
 * The requests used to validate `exists:addresses,id`, so an id nobody has was
 * a 422 while a stranger's passed validation and was refused by the service
 * with a 404 — and the difference told a signed-in customer which ids exist.
 * Ownership and existence are now one check, through the caller's own
 * addresses.
 */
class AddressIdDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    private array $catalog;

    private User $customer;

    private int $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();

        $this->customer = $this->customer('+201055550501');
        $this->theirs = $this->addressFor($this->customer('+201055550502'), $this->geo['zones'][0])->id;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fields(): array
    {
        return [
            'order, pickup' => ['/api/v1/orders', 'pickup_address_id'],
            'order, delivery' => ['/api/v1/orders', 'delivery_address_id'],
            'quote, pickup' => ['/api/v1/orders/quote', 'pickup_address_id'],
            'quote, delivery' => ['/api/v1/orders/quote', 'delivery_address_id'],
            'schedule, pickup' => ['/api/v1/recurrences', 'pickup_address_id'],
        ];
    }

    #[Test]
    #[DataProvider('fields')]
    public function a_strangers_id_and_nobodys_answer_the_same(string $uri, string $field): void
    {
        $own = $this->addressFor($this->customer, $this->geo['zones'][0])->id;
        Sanctum::actingAs($this->customer);

        $send = function (int $id) use ($uri, $field, $own) {
            $payload = [
                'service_id' => $this->catalog['service']->id,
                'pickup_address_id' => $own,
                'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
                'accepts_review_terms' => true,
                'frequency' => 'weekly',
                'day_of_week' => 3,
            ];
            // The field under test carries the id; the other end, if any, is
            // the customer's own address.
            $payload[$field] = $id;

            return $this->withHeaders($this->apiHeaders())->postJson($uri, $payload);
        };

        $stranger = $send($this->theirs);
        $nobody = $send(999999);

        $stranger->assertNotFound();
        $nobody->assertNotFound();
        $this->assertSame($stranger->json(), $nobody->json());
    }

    #[Test]
    public function a_repeat_schedule_question_that_is_nobodys_answers_like_a_strangers(): void
    {
        // `prompt_id` on the order carried the same `exists` rule.
        $stranger = $this->customer('+201055550503');
        $schedule = app(RecurrenceService::class)->create($stranger, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($stranger, $this->geo['zones'][0])->id,
            'frequency' => 'weekly',
            'day_of_week' => 3,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ]);
        $theirPrompt = RecurrencePrompt::create([
            'recurrence_id' => $schedule->id, 'prompted_for' => now()->toDateString(), 'prompted_at' => now(),
        ]);

        $own = $this->addressFor($this->customer, $this->geo['zones'][0])->id;
        Sanctum::actingAs($this->customer);

        $send = fn (int $promptId) => $this->withHeaders($this->apiHeaders())->postJson('/api/v1/orders', [
            'prompt_id' => $promptId,
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $own,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $strangers = $send($theirPrompt->id)->assertNotFound();
        $nobodys = $send(999999)->assertNotFound();

        $this->assertSame($strangers->json(), $nobodys->json());
    }

    #[Test]
    public function an_address_id_of_zero_is_refused_rather_than_read_as_the_same_address(): void
    {
        // Without `exists`, 0 would pass and `$deliveryId && …` would read it
        // as «same as the pickup» — a delivery quietly sent to the wrong door.
        Sanctum::actingAs($this->customer);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/orders/quote', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->addressFor($this->customer, $this->geo['zones'][0])->id,
            'delivery_address_id' => 0,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['delivery_address_id']]);
    }
}
