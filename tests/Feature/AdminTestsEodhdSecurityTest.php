<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTestsEodhdSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticker_provider_errors_do_not_expose_eodhd_tokens(): void
    {
        config(['services.eodhd.key' => 'configured-ticker-secret']);
        Http::fake([
            'eodhd.com/api/exchange-symbol-list/XETRA*' => Http::response([
                'status' => 'error',
                'message' => 'Failed URL: api_token=configured-ticker-secret&fmt=json',
            ]),
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson('/admin/tests/tickers')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Failed URL: api_token=[redacted]&fmt=json');

        $this->assertStringNotContainsString('configured-ticker-secret', $response->getContent());
    }

    public function test_exchange_detail_provider_errors_do_not_expose_eodhd_tokens(): void
    {
        config(['services.eodhd.key' => 'configured-exchange-secret']);
        Http::fake([
            'eodhd.com/api/exchanges-list/*' => Http::response([[
                'Code' => 'NASDAQ',
                'OperatingMIC' => 'XNAS',
            ]]),
            'eodhd.com/api/v2/exchange-details/XNAS*' => Http::response([
                'status' => 'error',
                'message' => 'Failed URL: apiToken=configured-exchange-secret&fmt=json',
            ]),
        ]);

        $response = $this->actingAs($this->adminUser())
            ->postJson('/admin/tests/exchanges')
            ->assertOk()
            ->assertJsonPath(
                'exchange_detail_errors.XNAS',
                'Failed URL: apiToken=[redacted]&fmt=json',
            );

        $this->assertStringNotContainsString('configured-exchange-secret', $response->getContent());
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
