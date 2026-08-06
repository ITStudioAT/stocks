<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_only_stock_and_index_market_data_tables(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->getJson('/admin/infos')
            ->assertOk();

        $responseTableNames = collect($response->json('tables'))->pluck('name')->all();
        $this->assertSame([
            'eodhd_exchanges',
            'index_watch_item_prices',
            'index_watch_items',
            'stock_holding_daily_prices',
            'stock_holding_intraday_candles',
            'stock_holdings',
            'stock_prices',
            'stock_realtime_prices',
        ], $responseTableNames);

        foreach ($response->json('tables') as $table) {
            $this->assertNotEmpty($table['eodhd']['documentation']);

            foreach ($table['eodhd']['documentation'] as $documentation) {
                $this->assertStringStartsWith('https://eodhd.com/financial-apis/', $documentation['url']);
            }
        }

        $methods = collect($response->json('methods'));
        $this->assertCount(8, $methods);

        foreach ($methods as $methodInformation) {
            $this->assertNotEmpty($methodInformation['triggers']);
            $this->assertNotEmpty($methodInformation['laravel_methods']);

            foreach ($methodInformation['laravel_methods'] as $laravelMethod) {
                $this->assertTrue(class_exists($laravelMethod['class']), "Class {$laravelMethod['class']} does not exist.");
                $this->assertTrue(
                    method_exists($laravelMethod['class'], $laravelMethod['method']),
                    "Method {$laravelMethod['class']}::{$laravelMethod['method']} does not exist.",
                );
            }
        }

        $stockRealtimeMethods = $methods->firstWhere('key', 'stock-realtime');
        $indexRealtimeMethods = $methods->firstWhere('key', 'index-realtime');
        $this->assertSame('mixed', $stockRealtimeMethods['execution']['mode']);
        $this->assertSame('default', $stockRealtimeMethods['execution']['queue']);
        $this->assertTrue($indexRealtimeMethods['execution']['scheduled']);
        $this->assertSame('default', $indexRealtimeMethods['execution']['queue']);
        $this->assertContains('stock_realtime_prices', $stockRealtimeMethods['tables']);

        $response
            ->assertJsonPath('tables.'.array_search('stock_realtime_prices', $responseTableNames, true).'.eodhd.mode', 'direct')
            ->assertJsonPath('tables.'.array_search('stock_realtime_prices', $responseTableNames, true).'.purpose_de', 'Kanonische Live-Aktienkurse und unverarbeitete EODHD-Antworten.')
            ->assertJsonPath('tables.'.array_search('stock_realtime_prices', $responseTableNames, true).'.queue.used', true)
            ->assertJsonPath('tables.'.array_search('stock_realtime_prices', $responseTableNames, true).'.queue.name', 'default')
            ->assertJsonPath('tables.'.array_search('stock_realtime_prices', $responseTableNames, true).'.eodhd.documentation.0.url', 'https://eodhd.com/financial-apis/live-ohlcv-stocks-api')
            ->assertJsonPath('tables.'.array_search('stock_holding_daily_prices', $responseTableNames, true).'.cadence', 'On demand when historical coverage is requested; no automatic schedule.')
            ->assertJsonPath('tables.'.array_search('index_watch_items', $responseTableNames, true).'.queue.used', true)
            ->assertJsonMissing(['name' => 'users'])
            ->assertJsonMissing(['name' => 'jobs'])
            ->assertJsonMissing(['name' => 'agent_conversations']);
    }

    public function test_guest_cannot_view_database_information(): void
    {
        $this->getJson('/admin/infos')->assertUnauthorized();
    }

    public function test_admin_can_open_the_infos_subpages(): void
    {
        $admin = $this->adminUser();

        foreach (['eodhd', 'methoden'] as $subpage) {
            $this->actingAs($admin)
                ->get("/admin/menu/infos/{$subpage}")
                ->assertOk();
        }
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
