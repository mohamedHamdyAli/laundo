<?php

namespace Tests\Unit;

use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_label_reads_as_the_design_writes_it(): void
    {
        $slot = TimeSlot::create([
            'start_time' => '14:00', 'end_time' => '17:00',
            'applies_to' => 'both', 'status' => 'active',
        ]);

        // "02:00 مساءً – 05:00 مساءً" in the design.
        $this->assertSame('02:00 PM – 05:00 PM', $slot->label());
    }

    public function test_midnight_and_noon_are_not_rendered_as_zero(): void
    {
        $midnight = TimeSlot::create([
            'start_time' => '00:00', 'end_time' => '00:30',
            'applies_to' => 'both', 'status' => 'active',
        ]);

        $noon = TimeSlot::create([
            'start_time' => '12:00', 'end_time' => '12:30',
            'applies_to' => 'both', 'status' => 'active',
        ]);

        $this->assertSame('12:00 AM – 12:30 AM', $midnight->label());
        $this->assertSame('12:00 PM – 12:30 PM', $noon->label());
    }

    public function test_applies_to_both_qualifies_for_either_purpose(): void
    {
        $both = TimeSlot::create(['start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both', 'status' => 'active']);
        $pickup = TimeSlot::create(['start_time' => '12:00', 'end_time' => '15:00', 'applies_to' => 'pickup', 'status' => 'active']);

        $this->assertTrue($both->appliesTo('pickup'));
        $this->assertTrue($both->appliesTo('delivery'));
        $this->assertTrue($pickup->appliesTo('pickup'));
        $this->assertFalse($pickup->appliesTo('delivery'));
    }
}
