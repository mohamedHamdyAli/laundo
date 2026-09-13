<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Enums\WalletOwnerType;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «محتاج اقسمها عشان اقدر افلتر» — the wallets list, by who owns the wallet.
 *
 * One wallets table serves everybody, which is right for the ledger and wrong
 * for the screen: «كام فلوس عند المغاسل» and «كام عند السواقين» are different
 * questions and the unfiltered list answers neither.
 *
 * The claim worth testing hardest is the asymmetry, because it is the one thing
 * about this screen somebody could reasonably call a bug: **the unfiltered list
 * hides empty wallets and a filtered one shows them.** Unfiltered the screen
 * answers «where is the money»; filtered, the operator asked «show me the
 * laundries», and answering that with only the ones holding a balance today is
 * how somebody concludes a laundry has no wallet at all.
 */
class WalletFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->grant('super_admin', ['wallet.view', 'wallet.update']);
    }

    /**
     * A user on a given role, with a wallet holding the given balance.
     */
    private function walletFor(string $roleSlug, string $name, string $phone, float $balance = 0.0): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower(str_replace(' ', '', $name)).'@test.local',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
        ]);

        $wallets = app(WalletService::class);
        $wallets->forUser($user);

        if ($balance > 0) {
            $wallets->credit($user, $balance, TransactionReason::TopUp);
        }

        return $user;
    }

    /** One of each, so every filter has something to find and something to exclude. */
    private function seedOneOfEach(): void
    {
        $this->walletFor('user', 'Rich Customer', '+201000000101', 100);
        $this->walletFor('user', 'Empty Customer', '+201000000102');
        $this->walletFor('driver', 'Rich Driver', '+201000000103', 50);
        $this->walletFor('laundry_owner', 'Empty Laundry Owner', '+201000000104');
        $this->walletFor('laundry_staff', 'Some Staff', '+201000000105', 7);
        $this->walletFor('super_admin', 'Platform Holder', '+201000000106', 25);
    }

    // ------------------------------------------------------------ the mapping

    #[Test]
    public function every_role_in_use_maps_to_a_group(): void
    {
        // The map is what the filter is built on, so a role that falls through
        // it is a wallet nobody can filter to.
        $this->assertSame(WalletOwnerType::Customer, WalletOwnerType::forRoleSlug('user'));
        $this->assertSame(WalletOwnerType::Driver, WalletOwnerType::forRoleSlug('driver'));
        $this->assertSame(WalletOwnerType::Laundry, WalletOwnerType::forRoleSlug('laundry_owner'));
        $this->assertSame(WalletOwnerType::LaundryStaff, WalletOwnerType::forRoleSlug('laundry_staff'));
        $this->assertSame(WalletOwnerType::Platform, WalletOwnerType::forRoleSlug('super_admin'));
        $this->assertSame(WalletOwnerType::Platform, WalletOwnerType::forRoleSlug('admin'));
    }

    #[Test]
    public function an_unrecognised_role_is_null_rather_than_defaulted(): void
    {
        // Null, not Customer: a role added later must show as unclassified on
        // the screen instead of being quietly folded into the customer totals.
        $this->assertNull(WalletOwnerType::forRoleSlug('some_future_role'));
        $this->assertNull(WalletOwnerType::forRoleSlug(null));
        $this->assertNull(WalletOwnerType::forRoleSlug(''));
    }

    #[Test]
    public function every_group_uses_a_pill_tone_that_exists(): void
    {
        // theme.css defines exactly these. A tone that is not there renders as
        // an unstyled pill — visible, wrong, and easy to miss in review.
        $defined = ['tone-live', 'tone-ok', 'tone-warn', 'tone-bad', 'tone-neutral', 'tone-brand'];

        foreach (WalletOwnerType::cases() as $case) {
            $this->assertContains($case->tone(), $defined, "{$case->value} uses an undefined tone");
        }

        $css = (string) file_get_contents(base_path('public/assets/css/theme.css'));

        foreach (WalletOwnerType::cases() as $case) {
            $this->assertStringContainsString(
                ".status-pill.{$case->tone()}",
                $css,
                "{$case->tone()} has no rule in theme.css"
            );
        }
    }

    // ------------------------------------------------------------ the filter

    #[Test]
    public function the_unfiltered_list_hides_empty_wallets(): void
    {
        $this->seedOneOfEach();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee('Rich Customer')
            ->assertDontSee('Empty Customer')
            ->assertDontSee('Empty Laundry Owner');
    }

    #[Test]
    public function a_filtered_list_shows_the_empty_ones_too(): void
    {
        $this->seedOneOfEach();

        // The whole point: a laundry with a wallet and no balance still has a
        // wallet, and the operator asked to see the laundries.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index', ['type' => 'laundry']))
            ->assertOk()
            ->assertSee('Empty Laundry Owner')
            ->assertDontSee('Rich Customer')
            ->assertDontSee('Rich Driver');
    }

    #[Test]
    public function each_group_returns_only_its_own(): void
    {
        $this->seedOneOfEach();

        $cases = [
            'customer' => ['Rich Customer', 'Rich Driver'],
            'driver' => ['Rich Driver', 'Rich Customer'],
            'laundry' => ['Empty Laundry Owner', 'Some Staff'],
            'laundry_staff' => ['Some Staff', 'Empty Laundry Owner'],
            'platform' => ['Platform Holder', 'Rich Customer'],
        ];

        foreach ($cases as $type => [$expected, $excluded]) {
            $this->actingAs($this->superAdmin())
                ->get(route('admin.wallet.index', ['type' => $type]))
                ->assertOk()
                ->assertSee($expected)
                ->assertDontSee($excluded);
        }
    }

    #[Test]
    public function an_unknown_group_falls_back_to_the_whole_list(): void
    {
        $this->seedOneOfEach();

        // tryFrom returns null, which is the same as no filter. A typed URL must
        // not 500 and must not silently return an empty screen either.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index', ['type' => 'nonsense']))
            ->assertOk()
            ->assertSee('Rich Customer')
            ->assertDontSee('Empty Customer');
    }

    // ------------------------------------------------------------ the totals

    #[Test]
    public function the_totals_follow_the_filter(): void
    {
        $this->seedOneOfEach();

        // Platform-wide totals sitting above a list of drivers is a number
        // nobody can reconcile against what they are looking at.
        $all = $this->actingAs($this->superAdmin())->get(route('admin.wallet.index'));
        $all->assertOk()->assertSee(moneyFormat(182), false);   // 100 + 50 + 7 + 25

        $drivers = $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index', ['type' => 'driver']));
        $drivers->assertOk()->assertSee(moneyFormat(50), false);
        $drivers->assertDontSee(moneyFormat(182), false);
    }

    // ------------------------------------------------------------ the search

    #[Test]
    public function the_search_respects_the_group(): void
    {
        $this->seedOneOfEach();

        // The pair drifting apart is what made searching surface rows the plain
        // list hides, so the two share one query builder.
        $response = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.wallet.search', ['query' => 'Rich', 'type' => 'driver']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        $table = (string) $response->json('table');

        $this->assertStringContainsString('Rich Driver', $table);
        $this->assertStringNotContainsString('Rich Customer', $table);
    }

    #[Test]
    public function searching_within_a_group_still_finds_an_empty_wallet(): void
    {
        $this->seedOneOfEach();

        $response = $this->actingAs($this->superAdmin())
            ->getJson(route('admin.wallet.search', ['query' => 'Empty', 'type' => 'laundry']), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertOk();

        // A row that appears while you type and vanishes when you clear the box
        // reads as data loss, so the two paths must apply the same rule.
        $this->assertStringContainsString('Empty Laundry Owner', (string) $response->json('table'));
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_row_names_the_group_it_belongs_to(): void
    {
        $this->seedOneOfEach();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee(__('Type'), false)
            ->assertSee(__(WalletOwnerType::Customer->singular()), false)
            ->assertSee(__(WalletOwnerType::Driver->singular()), false);
    }

    #[Test]
    public function the_screen_says_which_rule_it_is_applying(): void
    {
        $this->seedOneOfEach();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee(__('Only wallets holding money are listed. Pick a group to see every wallet in it, empty ones included.'), false);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet.index', ['type' => 'driver']))
            ->assertOk()
            ->assertSee(__('Every wallet in this group is listed, including the empty ones.'), false);
    }

    #[Test]
    public function a_laundry_owner_still_cannot_reach_the_list(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        // The filter did not widen anything: this list is not tenant-scoped, so
        // `wallet.view` remains the super admin's alone.
        $this->grant('laundry_owner', ['order.view']);

        $this->actingAs($tenant['owner'])
            ->get(route('admin.wallet.index', ['type' => 'laundry']))
            ->assertForbidden();
    }
}
