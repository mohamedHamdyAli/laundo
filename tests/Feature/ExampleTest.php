<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing route.
 *
 * This was the stock scaffold test and it failed on every run: without
 * RefreshDatabase there were no tables, and `/` renders the login view whose
 * layout chain reads the settings and languages tables.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_renders_for_a_guest(): void
    {
        $this->seedCore();

        $this->get('/')->assertOk();
    }

    public function test_a_signed_in_dashboard_user_is_redirected_to_home(): void
    {
        $this->seedCore();

        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertRedirect('/admin/home');
    }
}
