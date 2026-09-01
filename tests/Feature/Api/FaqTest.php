<?php

namespace Tests\Feature\Api;

use App\Modules\Faq\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الأسئلة الشائعة» — the first item under «المساعدة والدعم» in both apps.
 *
 * `audience` is the only part with any judgement in it. Both apps show the same
 * section and the answers are not the same: a driver asking when they get paid and
 * a customer asking when they get their clothes must not read each other's list.
 */
class FaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** @param array<string, string> $t */
    private function tr(array $t): string
    {
        return json_encode($t, JSON_UNESCAPED_UNICODE);
    }

    private function faq(string $en, string $audience = 'both', int $order = 0, string $status = 'active'): Faq
    {
        return Faq::create([
            'question' => $this->tr(['en' => $en, 'ar' => 'س: '.$en]),
            'answer' => $this->tr(['en' => 'A: '.$en, 'ar' => 'ج: '.$en]),
            'audience' => $audience,
            'order' => $order,
            'status' => $status,
        ]);
    }

    #[Test]
    public function the_list_is_public_because_help_is_needed_before_signing_in(): void
    {
        $this->faq('How do I order?');

        $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function only_active_entries_are_served(): void
    {
        $this->faq('Live one');
        $this->faq('Retired one', 'both', 0, 'inactive');

        $data = $this->getJson('/api/v1/faqs')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Live one', $data[0]['question']);
    }

    #[Test]
    public function a_customer_does_not_read_the_drivers_answers(): void
    {
        $this->faq('Shared question');
        $this->faq('When do I get paid?', 'driver');
        $this->faq('Where are my clothes?', 'customer');

        $customer = array_column(
            $this->getJson('/api/v1/faqs?audience=customer')->assertOk()->json('data'),
            'question'
        );

        // Its own plus the shared one, and nothing of the driver's.
        $this->assertContains('Shared question', $customer);
        $this->assertContains('Where are my clothes?', $customer);
        $this->assertNotContains('When do I get paid?', $customer);
    }

    #[Test]
    public function a_driver_does_not_read_the_customers_answers(): void
    {
        $this->faq('Shared question');
        $this->faq('When do I get paid?', 'driver');
        $this->faq('Where are my clothes?', 'customer');

        $driver = array_column(
            $this->getJson('/api/v1/faqs?audience=driver')->assertOk()->json('data'),
            'question'
        );

        $this->assertContains('Shared question', $driver);
        $this->assertContains('When do I get paid?', $driver);
        $this->assertNotContains('Where are my clothes?', $driver);
    }

    #[Test]
    public function no_audience_returns_everything_rather_than_guessing(): void
    {
        // Guessing from an absent token is how one app quietly gets the other's
        // list, so an unspecified audience is answered honestly with all of it.
        $this->faq('Shared');
        $this->faq('Driver only', 'driver');
        $this->faq('Customer only', 'customer');

        $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(3, 'data');
    }

    #[Test]
    public function an_unknown_audience_is_ignored_rather_than_returning_nothing(): void
    {
        $this->faq('Shared');

        // A typo in the query string must not empty the help screen.
        $this->getJson('/api/v1/faqs?audience=nonsense')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function entries_come_back_in_the_order_operations_set(): void
    {
        $this->faq('Third', 'both', 3);
        $this->faq('First', 'both', 1);
        $this->faq('Second', 'both', 2);

        $questions = array_column($this->getJson('/api/v1/faqs')->assertOk()->json('data'), 'question');

        $this->assertSame(['First', 'Second', 'Third'], $questions);
    }

    #[Test]
    public function two_entries_sharing_an_order_number_still_come_back_in_a_fixed_order(): void
    {
        // Without the id tie-break a help list reshuffles between visits.
        $first = $this->faq('Alpha', 'both', 1);
        $second = $this->faq('Beta', 'both', 1);

        $this->assertLessThan($second->id, $first->id);

        $questions = array_column($this->getJson('/api/v1/faqs')->assertOk()->json('data'), 'question');
        $this->assertSame(['Alpha', 'Beta'], $questions);
    }

    #[Test]
    public function the_list_follows_the_lang_header(): void
    {
        $this->faq('How do I order?');

        $this->assertSame(
            'س: How do I order?',
            $this->getJson('/api/v1/faqs', ['lang' => 'ar'])->assertOk()->json('data.0.question')
        );

        $this->assertSame(
            'How do I order?',
            $this->getJson('/api/v1/faqs', ['lang' => 'en'])->assertOk()->json('data.0.question')
        );
    }

    // ------------------------------------------------------------- dashboard

    #[Test]
    public function operations_can_create_and_edit_a_question(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/admin/faq/store', [
            'question' => ['en' => 'How do I pay?', 'ar' => 'كيف أدفع؟'],
            'answer' => ['en' => 'Cash or card.', 'ar' => 'نقداً أو بالبطاقة.'],
            'audience' => 'customer',
            'order' => 2,
            'status' => 'active',
        ])->assertRedirect();

        $faq = Faq::firstOrFail();

        // Arabic stored as Arabic, not \uXXXX — the asJson() override.
        $this->assertSame('كيف أدفع؟', $faq->question->ar);
        $this->assertSame('customer', $faq->audience);

        $this->put('/admin/faq/update/'.$faq->id, ['order' => 5])->assertRedirect();

        $faq->refresh();

        // An edit that only moves the order must not blank the text.
        $this->assertSame(5, $faq->order);
        $this->assertSame('كيف أدفع؟', $faq->question->ar);
    }

    #[Test]
    public function a_laundry_owner_cannot_edit_the_help_content(): void
    {
        $tenant = $this->laundryWithOwner('A', '01011110001', '01011110002');

        $this->actingAs($tenant['owner'])
            ->get('/admin/faq')
            ->assertForbidden();
    }
}
