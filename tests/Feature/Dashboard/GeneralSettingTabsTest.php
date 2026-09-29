<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الإعدادات» in four tabs — one form, so a save still posts every field and a
 * tab nobody opened is never blanked.
 */
class GeneralSettingTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
    }

    private function page(): string
    {
        return $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_settings_sit_in_four_tabs_inside_one_form(): void
    {
        $html = $this->page();

        foreach (['general', 'contact', 'money', 'operations'] as $tab) {
            $this->assertStringContainsString('id="settings-tab-'.$tab.'"', $html);
            $this->assertStringContainsString('id="settings-'.$tab.'"', $html);
        }

        // One form around all four, so nothing on a closed tab is lost on save.
        $this->assertSame(1, substr_count($html, 'id="form_with_disabled"'));
        $form = substr($html, strpos($html, 'id="form_with_disabled"'));
        $this->assertStringContainsString('id="settings-operations"', substr($form, 0, strpos($form, '</form>')));
    }

    #[Test]
    public function related_settings_share_a_tab(): void
    {
        $html = $this->page();
        $pane = function (string $tab) use ($html): string {
            $start = strpos($html, 'id="settings-'.$tab.'"');
            $next = strpos($html, 'class="tab-pane', $start + 1);

            return substr($html, $start, $next === false ? null : $next - $start);
        };

        $this->assertStringContainsString('name="Tax"', $pane('money'));
        $this->assertStringContainsString('name="Laundry_Share_Rate"', $pane('money'));
        $this->assertStringContainsString('name="Slot_Overflow_Behavior"', $pane('operations'));
        $this->assertStringContainsString('name="App_Name"', $pane('general'));
    }

    #[Test]
    public function the_cash_handling_fee_can_be_set_from_the_screen_at_last(): void
    {
        $this->assertStringContainsString('name="Cash_Surcharge"', $this->page());

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), ['Cash_Surcharge' => '7.5'])
            ->assertRedirect();

        $this->assertSame('7.5', Setting::where('key', 'Cash_Surcharge')->value('value'));
    }
}
