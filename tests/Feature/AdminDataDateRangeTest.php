<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_data_date_range_endpoints_return_not_found(): void
    {
        $this->actingAs($this->adminUser());

        foreach (['indices', 'stocks'] as $section) {
            foreach (['live-data', 'intraday-data', 'eod-data'] as $type) {
                $this->getJson("/admin/data/{$section}/{$type}/date-range")->assertNotFound();
                $this->getJson("/admin/data/{$section}/1/{$type}/date-range")->assertNotFound();
            }
        }
    }

    public function test_removed_date_range_endpoints_also_return_not_found_for_guests(): void
    {
        $this->getJson('/admin/data/indices/live-data/date-range')->assertNotFound();
        $this->getJson('/admin/data/stocks/1/live-data/date-range')->assertNotFound();
    }

    public function test_data_overview_url_can_be_loaded_directly_by_an_admin(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/menu/data/overview')
            ->assertOk();
    }

    public function test_three_level_data_menu_url_can_be_loaded_directly(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/menu/data/stocks/eod-data')
            ->assertOk();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
