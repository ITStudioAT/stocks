<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\StockPriceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_each_weekday_of_the_current_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '900.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '130.000000',
                'currency' => 'EUR',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '100.00',
                'unit_price' => '100.00000000',
                'cash_delta' => '-100.00',
                'balance_after' => '900.00',
                'booked_at' => '2026-06-30 00:00:00',
            ]);

            $instrumentKey = app(StockPriceCatalog::class)->instrumentKeyForHolding($holding);

            collect([
                ['date' => '2026-06-30', 'price' => '100.00000000'],
                ['date' => '2026-07-24', 'price' => '110.00000000'],
                ['date' => '2026-07-31', 'price' => '120.00000000'],
                ['date' => '2026-08-03', 'price' => '120.00000000'],
                ['date' => '2026-08-04', 'price' => '120.00000000'],
                ['date' => '2026-08-05', 'price' => '125.00000000'],
                ['date' => '2026-08-06', 'price' => '120.00000000'],
            ])->each(fn (array $price): StockPrice => StockPrice::factory()->create([
                'instrument_key' => $instrumentKey,
                'source_key' => 'eodhd_eod',
                'price_type' => 'historical_eod',
                'currency' => 'EUR',
                'price' => $price['price'],
                'as_of' => Carbon::parse("{$price['date']} 23:59:59", 'Europe/Vienna'),
                'fetched_at' => Carbon::parse("{$price['date']} 23:59:59", 'Europe/Vienna'),
                'freshness_status' => 'historical',
            ]));
            $livePrice = StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'currency' => 'EUR',
                'price' => '130.00000000',
                'as_of' => Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'),
                'fetched_at' => Carbon::parse('2026-08-07 12:01:00', 'Europe/Vienna'),
                'freshness_status' => 'realtime',
            ]);

            $holding->update([
                'latest_realtime_price_id' => $livePrice->id,
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/dashboard/performance')
                ->assertOk()
                ->assertJsonCount(5, 'days')
                ->assertJsonPath('days.0.date', '2026-08-03')
                ->assertJsonPath('days.0.is_live', false)
                ->assertJsonPath('days.0.change_amount', '0.00')
                ->assertJsonPath('days.1.date', '2026-08-04')
                ->assertJsonPath('days.1.is_live', false)
                ->assertJsonPath('days.1.change_amount', '0.00')
                ->assertJsonPath('days.2.date', '2026-08-05')
                ->assertJsonPath('days.2.change_amount', '5.00')
                ->assertJsonPath('days.2.change_percent', '0.49')
                ->assertJsonPath('days.3.date', '2026-08-06')
                ->assertJsonPath('days.3.change_amount', '-5.00')
                ->assertJsonPath('days.3.change_percent', '-0.49')
                ->assertJsonPath('days.4.date', '2026-08-07')
                ->assertJsonPath('days.4.is_live', true)
                ->assertJsonPath('days.4.change_amount', '10.00')
                ->assertJsonPath('days.4.change_percent', '0.98')
                ->assertJsonCount(5, 'sums')
                ->assertJsonPath('sums.0.period', 'week')
                ->assertJsonPath('sums.0.change_amount', '10.00')
                ->assertJsonPath('sums.0.change_percent', '0.98')
                ->assertJsonPath('sums.1.period', 'last_week')
                ->assertJsonPath('sums.1.change_amount', '10.00')
                ->assertJsonPath('sums.1.change_percent', '0.99')
                ->assertJsonPath('sums.2.period', 'month')
                ->assertJsonPath('sums.2.change_amount', '10.00')
                ->assertJsonPath('sums.2.change_percent', '0.98')
                ->assertJsonPath('sums.3.period', 'last_month')
                ->assertJsonPath('sums.3.change_amount', '20.00')
                ->assertJsonPath('sums.3.change_percent', '2.00')
                ->assertJsonPath('sums.4.period', 'year')
                ->assertJsonPath('sums.4.change_amount', '30.00')
                ->assertJsonPath('sums.4.change_percent', '3.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_performance_is_empty_without_an_active_depot(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/admin/dashboard/performance')
            ->assertOk()
            ->assertExactJson([
                'days' => [],
                'sums' => [],
            ]);
    }

    public function test_last_month_percentage_excludes_later_external_cash_flows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));

        try {
            $depot = Depot::factory()->create([
                'account_balance' => '2100.00',
                'is_active' => true,
            ]);

            collect([
                [
                    'type' => 'opening_balance',
                    'total_amount' => '1000.00',
                    'cash_delta' => '1000.00',
                    'balance_after' => '1000.00',
                    'booked_at' => '2026-01-01 00:00:00',
                ],
                [
                    'type' => 'dividend',
                    'total_amount' => '100.00',
                    'cash_delta' => '100.00',
                    'balance_after' => '1100.00',
                    'booked_at' => '2026-07-15 00:00:00',
                ],
                [
                    'type' => 'deposit',
                    'total_amount' => '1000.00',
                    'cash_delta' => '1000.00',
                    'balance_after' => '2100.00',
                    'booked_at' => '2026-08-04 00:00:00',
                ],
            ])->each(fn (array $transaction): DepotTransaction => DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                ...$transaction,
            ]));

            $this->actingAs($this->adminUser())
                ->getJson('/admin/dashboard/performance')
                ->assertOk()
                ->assertJsonPath('sums.3.period', 'last_month')
                ->assertJsonPath('sums.3.change_amount', '100.00')
                ->assertJsonPath('sums.3.change_percent', '10.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_cannot_view_dashboard_performance(): void
    {
        $this->getJson('/admin/dashboard/performance')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
