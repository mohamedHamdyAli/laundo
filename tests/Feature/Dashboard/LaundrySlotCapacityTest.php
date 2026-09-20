<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Models\LaundrySlotCapacity;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two capacity editors, and the boundary around them.
 *
 * The boundary is the point of half these tests. Capacity decides how much work
 * a laundry is handed, so its owner must not hold the dial — the same rule as
 * the commission rate, written down in CLAUDE.md as «gating its commission on
 * `laundry.update` would hand the payer the dial». A laundry owner holds
 * `laundry.update` by design, which is exactly why this is a separate
 * permission and why `seedCore()` does not grant it to them.
 */
class LaundrySlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Laundry $laundry;

    private User $owner;

    private User $admin;

    private TimeSlot $morning;

    private TimeSlot $evening;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();

        $pair = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->laundry = $pair['laundry'];
        $this->owner = $pair['owner'];
        $this->admin = $this->superAdmin();

        $this->morning = TimeSlot::create([
            'start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both',
            'capacity' => null, 'sort_order' => 1, 'status' => 'active',
        ]);

        $this->evening = TimeSlot::create([
            'start_time' => '18:00', 'end_time' => '21:00', 'applies_to' => 'both',
            'capacity' => null, 'sort_order' => 2, 'status' => 'active',
        ]);
    }

    #[Test]
    public function a_super_admin_can_see_the_grid(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.laundry_slot_capacity.index'))
            ->assertOk()
            ->assertSee('09:00 AM – 12:00 PM');
    }

    #[Test]
    public function the_grid_saves_a_number_per_laundry_per_window(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.laundry_slot_capacity.update'), [
                'capacities' => [
                    $this->laundry->id => [
                        $this->morning->id => 5,
                        $this->evening->id => 3,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.laundry_slot_capacity.index'));

        $this->assertSame(5, $this->capacityFor($this->morning));
        $this->assertSame(3, $this->capacityFor($this->evening));
    }

    #[Test]
    public function a_blank_cell_removes_the_limit_rather_than_setting_it_to_zero(): void
    {
        $this->saveAs($this->admin, [$this->morning->id => 5]);
        $this->assertSame(5, $this->capacityFor($this->morning));

        $this->saveAs($this->admin, [$this->morning->id => '']);

        // No row at all, which is what «no limit» is. A row holding null would
        // say the same thing and then sit there forever saying it.
        $this->assertNull($this->capacityFor($this->morning));
        $this->assertSame(0, LaundrySlotCapacity::withoutGlobalScopes()
            ->where('laundry_id', $this->laundry->id)
            ->where('time_slot_id', $this->morning->id)
            ->count());
    }

    #[Test]
    public function zero_is_kept_because_a_closed_window_is_not_an_absent_one(): void
    {
        $this->saveAs($this->admin, [$this->morning->id => 0]);

        $this->assertSame(0, $this->capacityFor($this->morning));
    }

    #[Test]
    public function saving_one_laundry_leaves_the_others_alone(): void
    {
        $other = $this->laundryWithOwner('B', '+201022220001', '+201022220002')['laundry'];

        $this->actingAs($this->admin)->put(route('admin.laundry_slot_capacity.update'), [
            'capacities' => [
                $this->laundry->id => [$this->morning->id => 4],
                $other->id => [$this->morning->id => 9],
            ],
        ]);

        // Now the laundry edit tab posts, carrying only its own row.
        $this->actingAs($this->admin)->put(route('admin.laundry_slot_capacity.update'), [
            'capacities' => [$this->laundry->id => [$this->morning->id => 6]],
            'return_to_laundry' => $this->laundry->id,
        ])->assertRedirect(route('admin.laundry.edit', $this->laundry->id));

        $this->assertSame(6, $this->capacityFor($this->morning));
        $this->assertSame(9, $this->capacityFor($this->morning, $other->id), 'The other laundry was not in the payload and must not have been touched.');
    }

    #[Test]
    public function a_laundry_owner_cannot_set_their_own_capacity(): void
    {
        // They hold `laundry.update` — the panel gives it to them so they can
        // edit their own details — and that must not carry this with it.
        $held = $this->owner->role->permissions->pluck('slug');

        $this->assertContains('laundry.update', $held);
        $this->assertNotContains('laundry_slot_capacity.update', $held);

        $this->actingAs($this->owner)
            ->get(route('admin.laundry_slot_capacity.index'))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('admin.laundry_slot_capacity.update'), [
                'capacities' => [$this->laundry->id => [$this->morning->id => 999]],
            ])
            ->assertForbidden();

        $this->assertNull($this->capacityFor($this->morning));
    }

    #[Test]
    public function a_capacity_beyond_the_ceiling_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.laundry_slot_capacity.update'), [
                'capacities' => [$this->laundry->id => [$this->morning->id => 9999]],
            ])
            ->assertSessionHasErrors();

        $this->assertNull($this->capacityFor($this->morning));
    }

    #[Test]
    public function the_capacity_tab_renders_on_the_laundry_edit_screen(): void
    {
        $this->saveAs($this->admin, [$this->morning->id => 7]);

        $this->actingAs($this->admin)
            ->get(route('admin.laundry.edit', $this->laundry->id))
            ->assertOk()
            ->assertSee('09:00 AM – 12:00 PM')
            ->assertSee('value="7"', false);
    }

    private function saveAs(User $actor, array $cells): void
    {
        $this->actingAs($actor)->put(route('admin.laundry_slot_capacity.update'), [
            'capacities' => [$this->laundry->id => $cells],
        ]);
    }

    private function capacityFor(TimeSlot $slot, ?int $laundryId = null): ?int
    {
        return LaundrySlotCapacity::withoutGlobalScopes()
            ->where('laundry_id', $laundryId ?? $this->laundry->id)
            ->where('time_slot_id', $slot->id)
            ->value('capacity');
    }
}
