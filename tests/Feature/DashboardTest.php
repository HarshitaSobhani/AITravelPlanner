<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/trips')->assertRedirect('/login');
    }

    public function test_dashboard_leads_to_the_trip_list()
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/trips');
    }

    public function test_empty_trip_list_renders_without_invented_data()
    {
        $this->actingAs(User::factory()->create())->get('/trips')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Trips/Index')->where('trips', []));
    }
}
