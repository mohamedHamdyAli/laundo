<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\User\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «نسيت كلمة المرور» for a laundry owner.
 *
 * A laundry carries two email addresses and they are usually different: the
 * shop's contact address on `laundries.email` and the owner's personal sign-in
 * address on `users.email`. The owner does not experience those as two things —
 * they know the address printed on their receipts, they type it, and the stock
 * broker answered «We can't find a user with that email address».
 *
 * Literally true, and it reads as «you have no account».
 */
class LaundryPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Laundry $laundry;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        Notification::fake();

        $pair = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->laundry = $pair['laundry'];
        $this->owner = $pair['owner'];
    }

    private function forgot(string $email)
    {
        return $this->post('/password/email', ['email' => $email]);
    }

    #[Test]
    public function the_two_addresses_really_are_different(): void
    {
        // The premise. If a fixture ever made them equal, every test below would
        // pass while proving nothing.
        $this->assertNotSame($this->laundry->email, $this->owner->email);
    }

    #[Test]
    public function a_laundrys_business_address_reaches_its_owner(): void
    {
        $this->forgot($this->laundry->email)->assertSessionHas('status');

        Notification::assertSentTo($this->owner, ResetPassword::class);
    }

    #[Test]
    public function the_link_goes_to_the_owners_inbox_not_the_address_typed(): void
    {
        // The property that makes accepting a public `info@` address safe at
        // all: anyone can read the shop's mailbox, only the owner can read
        // theirs. Laravel routes the notification to the *user* it found, so
        // this asserts the found user is the owner and that the mail is aimed
        // at their own address rather than the one that was typed.
        $this->forgot($this->laundry->email);

        Notification::assertSentTo(
            $this->owner,
            ResetPassword::class,
            fn ($notification, $channels, $notifiable) => $notifiable->email === $this->owner->email
                && $notifiable->email !== $this->laundry->email
        );
    }

    #[Test]
    public function the_owners_own_address_still_works(): void
    {
        $this->forgot($this->owner->email)->assertSessionHas('status');

        Notification::assertSentTo($this->owner, ResetPassword::class);
    }

    #[Test]
    public function an_ordinary_dashboard_account_is_untouched(): void
    {
        // Not a customer: **customers have no email at all** on this system.
        // They are created by phone through the OTP flow and `TestCase::customer()`
        // writes none, so this form is for dashboard accounts — which is why the
        // laundry case is most of its traffic.
        $admin = $this->superAdmin();

        $this->forgot($admin->email)->assertSessionHas('status');

        Notification::assertSentTo($admin, ResetPassword::class);
    }

    #[Test]
    public function an_address_nobody_holds_is_still_refused(): void
    {
        $this->forgot('nobody@example.test')->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    #[Test]
    public function one_contact_address_can_only_ever_name_one_business(): void
    {
        // Why the lookup is a plain `first()` and not a count: the schema forbids
        // two laundries sharing a contact address, so there is no second owner a
        // reset could reach by mistake. Asserted rather than assumed — the
        // translation stops being safe the day that index is dropped.
        $second = $this->laundryWithOwner('B', '+201022220001', '+201022220002');

        $this->expectException(UniqueConstraintViolationException::class);

        $second['laundry']->forceFill(['email' => $this->laundry->email])->save();
    }

    #[Test]
    public function a_laundry_with_no_owner_row_fails_rather_than_erroring(): void
    {
        $this->owner->forceDelete();

        $this->forgot($this->laundry->email)->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_real_account_wins_over_a_laundry_holding_the_same_address(): void
    {
        // Somebody's personal address also entered as a shop contact. The
        // account is the answer; the translation must not redirect away from it.
        $person = $this->superAdmin();
        $this->laundry->forceFill(['email' => $person->email])->save();

        $this->forgot($person->email);

        Notification::assertSentTo($person, ResetPassword::class);
        Notification::assertNotSentTo($this->owner, ResetPassword::class);
    }

    #[Test]
    public function a_pending_owner_can_still_reset(): void
    {
        // A laundry awaiting approval has an inactive owner. Sign-in is gated on
        // `status = active`, but recovering a password is how they are ready for
        // the day they are approved — and the broker has never filtered on it.
        $this->owner->forceFill(['status' => 'inactive'])->save();

        $this->forgot($this->laundry->email)->assertSessionHas('status');

        Notification::assertSentTo($this->owner, ResetPassword::class);
    }
}
