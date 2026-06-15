<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepotTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_book_cash_transactions_on_the_active_depot(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'deposit',
                'total_amount' => '250.25',
                'booked_at' => '2026-06-05',
                'note' => 'Funding',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Cash transaction booked.')
            ->assertJsonPath('depot.account_balance', '1250.25')
            ->assertJsonPath('transaction.type', 'deposit')
            ->assertJsonPath('transaction.total_amount', '250.25')
            ->assertJsonPath('transaction.cash_delta', '250.25')
            ->assertJsonPath('transaction.balance_after', '1250.25');

        $this->assertDatabaseHas('depot_transactions', [
            'depot_id' => $depot->id,
            'type' => 'deposit',
            'total_amount' => '250.25',
            'balance_after' => '1250.25',
            'booked_at' => '2026-06-05 00:00:00',
            'note' => 'Funding',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'withdrawal',
                'total_amount' => '100.00',
            ])
            ->assertCreated()
            ->assertJsonPath('depot.account_balance', '1150.25')
            ->assertJsonPath('transaction.cash_delta', '-100.00');

        $this->assertSame('1150.25', $depot->refresh()->account_balance);
    }

    public function test_admin_can_book_stock_buy_and_sell_transactions(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $holding->id,
                'pieces' => '2.5',
                'total_amount' => '500.00',
                'booked_at' => '2026-06-05',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Stock transaction booked.')
            ->assertJsonPath('depot.account_balance', '500.00')
            ->assertJsonPath('transaction.type', 'buy')
            ->assertJsonPath('transaction.pieces', '2.50000000')
            ->assertJsonPath('transaction.unit_price', '200.00000000')
            ->assertJsonPath('transaction.cash_delta', '-500.00');

        $this->assertSame('2026-06-05', $response->json('transaction.booked_at')
            ? substr($response->json('transaction.booked_at'), 0, 10)
            : null);

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.position_pieces', '2.50000000');

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'sell',
                'stock_holding_id' => $holding->id,
                'pieces' => '1.5',
                'total_amount' => '330.00',
            ])
            ->assertCreated()
            ->assertJsonPath('depot.account_balance', '830.00')
            ->assertJsonPath('transaction.type', 'sell')
            ->assertJsonPath('transaction.cash_delta', '330.00');

        $this->actingAs($admin)
            ->getJson('/admin/watchlist/holdings')
            ->assertOk()
            ->assertJsonPath('holdings.0.position_pieces', '1.00000000');
    }

    public function test_stock_transactions_validate_cash_and_owned_pieces(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '100.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $holding->id,
                'pieces' => '1',
                'total_amount' => '101.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount');

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'sell',
                'stock_holding_id' => $holding->id,
                'pieces' => '1',
                'total_amount' => '50.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pieces');

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $holding->id,
                'pieces' => '1',
                'total_amount' => '50.00',
                'booked_at' => 'not-a-date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booked_at');

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'deposit',
                'total_amount' => '50.00',
                'booked_at' => 'not-a-date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booked_at');

        $this->assertSame('100.00', $depot->refresh()->account_balance);
    }

    public function test_admin_can_list_depot_transactions(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '0.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'deposit',
                'total_amount' => '500.00',
                'booked_at' => '2026-06-01',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $holding->id,
                'pieces' => '1',
                'total_amount' => '125.00',
                'booked_at' => '2026-06-02',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'withdrawal',
                'total_amount' => '100.00',
                'booked_at' => '2026-06-03',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonCount(3, 'transactions')
            ->assertJsonPath('transactions.0.type', 'withdrawal')
            ->assertJsonPath('transactions.0.cash_delta', '-100.00')
            ->assertJsonPath('transactions.0.balance_after', '275.00')
            ->assertJsonPath('transactions.1.type', 'buy')
            ->assertJsonPath('transactions.1.stock_label', 'Apple Inc.')
            ->assertJsonPath('transactions.1.stock_isin', 'US0378331005')
            ->assertJsonPath('transactions.1.cash_delta', '-125.00')
            ->assertJsonPath('transactions.1.balance_after', '375.00')
            ->assertJsonPath('transactions.2.type', 'deposit')
            ->assertJsonPath('transactions.2.cash_delta', '500.00')
            ->assertJsonPath('transactions.2.balance_after', '500.00');
    }

    public function test_admin_can_update_a_depot_transaction_date(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '100.00',
            'is_active' => true,
        ]);
        $transaction = DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => 'deposit',
            'pieces' => null,
            'total_amount' => '100.00',
            'unit_price' => null,
            'cash_delta' => '100.00',
            'balance_after' => '100.00',
            'booked_at' => '2026-06-04 09:00:00',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/depot-transactions/{$transaction->id}/date", [
                'booked_at' => '2026-06-03',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Transaction date updated.')
            ->assertJsonPath('transaction.id', $transaction->id);

        $this->assertSame('2026-06-03 00:00:00', $transaction->refresh()->booked_at->format('Y-m-d H:i:s'));
    }

    public function test_admin_cannot_update_a_transaction_date_for_another_depot(): void
    {
        $admin = $this->adminUser();
        Depot::factory()->create([
            'account_balance' => '100.00',
            'is_active' => true,
        ]);
        $otherDepot = Depot::factory()->create([
            'account_balance' => '100.00',
            'is_active' => false,
        ]);
        $transaction = DepotTransaction::factory()->create([
            'depot_id' => $otherDepot->id,
            'stock_holding_id' => null,
            'type' => 'deposit',
            'pieces' => null,
            'total_amount' => '100.00',
            'unit_price' => null,
            'cash_delta' => '100.00',
            'balance_after' => '100.00',
            'booked_at' => '2026-06-04 09:00:00',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/depot-transactions/{$transaction->id}/date", [
                'booked_at' => '2026-06-03',
            ])
            ->assertNotFound();

        $this->assertSame('2026-06-04 09:00:00', $transaction->refresh()->booked_at->format('Y-m-d H:i:s'));
    }

    public function test_admin_must_provide_a_valid_transaction_date_when_updating(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '100.00',
            'is_active' => true,
        ]);
        $transaction = DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => 'deposit',
            'pieces' => null,
            'total_amount' => '100.00',
            'unit_price' => null,
            'cash_delta' => '100.00',
            'balance_after' => '100.00',
            'booked_at' => '2026-06-04 09:00:00',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/depot-transactions/{$transaction->id}/date", [
                'booked_at' => '03.06.2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booked_at');
    }

    public function test_admin_can_list_opening_cash_deposit_transaction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '56956.56',
                'is_active' => true,
                'created_at' => '2026-06-03 14:26:50',
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '150.000000',
                'flatex_price' => '150.000000',
            ]);
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-05',
                'close' => '140.00000000',
                'adjusted_close' => '140.00000000',
            ]);

            $deposit = DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'deposit',
                'pieces' => null,
                'total_amount' => '83236.56',
                'unit_price' => null,
                'cash_delta' => '83236.56',
                'balance_after' => '83236.56',
                'booked_at' => '2026-01-01 00:00:00',
                'note' => 'Opening cash balance',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '13260.00',
                'unit_price' => '13260.00000000',
                'cash_delta' => '-13260.00',
                'balance_after' => '70216.56',
                'booked_at' => '2026-06-04 18:07:41',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '13020.00',
                'unit_price' => '13020.00000000',
                'cash_delta' => '-13020.00',
                'balance_after' => '56956.56',
                'booked_at' => '2026-06-04 18:08:17',
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonCount(3, 'transactions')
                ->assertJsonPath('transactions.2.id', $deposit->id)
                ->assertJsonPath('transactions.2.type', 'deposit')
                ->assertJsonPath('transactions.2.total_amount', '83236.56')
                ->assertJsonPath('transactions.2.cash_delta', '83236.56')
                ->assertJsonPath('transactions.2.balance_after', '83236.56')
                ->assertJsonPath('transactions.2.note', 'Opening cash balance')
                ->assertJsonPath('depot_valuations.latest.year_start_balance', '83236.56')
                ->assertJsonPath('depot_valuations.latest.current_balance', '57256.56')
                ->assertJsonPath('depot_valuations.latest.balance_change_amount', '-25980.00')
                ->assertJsonPath('depot_valuations.latest.balance_change_percent', '-31.21')
                ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '57236.56')
                ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '20.00')
                ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '0.03');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_list_depot_performance_series_from_year_start_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-13 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '800.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '150.000000',
                'flatex_price' => '150.000000',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'deposit',
                'pieces' => null,
                'total_amount' => '1000.00',
                'unit_price' => null,
                'cash_delta' => '1000.00',
                'balance_after' => '1000.00',
                'booked_at' => '2026-01-01 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '2.00000000',
                'total_amount' => '200.00',
                'unit_price' => '100.00000000',
                'cash_delta' => '-200.00',
                'balance_after' => '800.00',
                'booked_at' => '2026-02-01 00:00:00',
            ]);
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-02-01',
                'close' => '120.00000000',
                'adjusted_close' => '120.00000000',
            ]);

            $response = $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonPath('depot_performance_series.0.date', '2026-01-01')
                ->assertJsonPath('depot_performance_series.0.account_balance', '1000.00');

            $series = collect($response->json('depot_performance_series'));
            $februaryFirstPoint = $series->firstWhere('date', '2026-02-01');
            $latestPoint = $series->last();

            $this->assertSame('240.00', $februaryFirstPoint['stock_balance']);
            $this->assertSame('800.00', $februaryFirstPoint['cash_balance']);
            $this->assertSame('1040.00', $februaryFirstPoint['account_balance']);
            $this->assertSame('2026-06-13', $latestPoint['date']);
            $this->assertSame('300.00', $latestPoint['stock_balance']);
            $this->assertSame('1100.00', $latestPoint['account_balance']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_list_current_stock_positions_for_the_active_depot(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => 'deposit',
            'pieces' => null,
            'total_amount' => '1000.00',
            'unit_price' => null,
            'cash_delta' => '1000.00',
            'balance_after' => '1000.00',
            'booked_at' => '2026-01-01 00:00:00',
        ]);
        $otherDepot = Depot::factory()->create([
            'is_active' => false,
        ]);
        $ownedHolding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'currency' => 'USD',
            'latest_price' => '191.500000',
            'flatex_price' => '191.500000',
        ]);
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $ownedHolding->id,
            'trading_date' => '2026-06-11',
            'close' => '185.00000000',
            'adjusted_close' => '185.00000000',
            'currency' => 'USD',
        ]);
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $ownedHolding->id,
            'trading_date' => '2026-06-12',
            'close' => '188.00000000',
            'adjusted_close' => '188.00000000',
            'currency' => 'USD',
        ]);
        $soldHolding = StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corp.',
        ]);
        $otherDepotHolding = StockHolding::factory()->create([
            'symbol' => 'GOOG',
            'name' => 'Alphabet Inc.',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $ownedHolding->id,
                'pieces' => '2.5',
                'total_amount' => '300.00',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $soldHolding->id,
                'pieces' => '1',
                'total_amount' => '100.00',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'sell',
                'stock_holding_id' => $soldHolding->id,
                'pieces' => '1',
                'total_amount' => '110.00',
            ])
            ->assertCreated();

        DepotTransaction::factory()->create([
            'depot_id' => $otherDepot->id,
            'stock_holding_id' => $otherDepotHolding->id,
            'type' => 'buy',
            'pieces' => '10.00000000',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonCount(1, 'depot_holdings')
            ->assertJsonPath('depot_holdings.0.symbol', 'AAPL')
            ->assertJsonPath('depot_holdings.0.name', 'Apple Inc.')
            ->assertJsonPath('depot_holdings.0.isin', 'US0378331005')
            ->assertJsonPath('depot_holdings.0.latest_price', '191.500000')
            ->assertJsonPath('depot_holdings.0.previous_day_price', '188.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_price_date', '2026-06-12')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '1.86')
            ->assertJsonPath('depot_holdings.0.flatex_price', '191.500000')
            ->assertJsonPath('depot_holdings.0.year_start_price', '120.00000000')
            ->assertJsonPath('depot_holdings.0.currency', 'USD')
            ->assertJsonPath('depot_holdings.0.position_pieces', '2.50000000')
            ->assertJsonPath('depot_valuations.latest.stock_balance', '478.75')
            ->assertJsonPath('depot_valuations.latest.cash_balance', '710.00')
            ->assertJsonPath('depot_valuations.latest.account_balance', '1188.75')
            ->assertJsonPath('depot_valuations.latest.year_start_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1188.75')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '188.75')
            ->assertJsonPath('depot_valuations.latest.balance_change_percent', '18.88')
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '188.75')
            ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '18.88');

        $this->assertSame(['AAPL'], collect($response->json('depot_holdings'))->pluck('symbol')->all());
        $this->assertSame('710.00', $depot->refresh()->account_balance);
    }

    public function test_depot_holding_payload_uses_latest_realtime_price_when_available(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'currency' => 'USD',
            'latest_price' => '100.000000',
            'latest_price_fetched_at' => Carbon::parse('2026-06-12 16:00:00', 'UTC'),
            'latest_price_as_of' => '2026-06-12T14:00:00+00:00',
        ]);
        $realtimePrice = StockRealtimePrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'symbol' => 'AAPL',
            'isin' => 'US0378331005',
            'currency' => 'USD',
            'price' => '121.25000000',
            'as_of' => Carbon::parse('2026-06-12 17:30:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-15 07:23:00', 'UTC'),
            'freshness_status' => 'realtime',
        ]);
        $holding->update([
            'latest_realtime_price_id' => $realtimePrice->id,
        ]);
        $previousStoredPrice = StockPrice::factory()->create([
            'symbol' => 'AAPL',
            'isin' => 'US0378331005',
            'currency' => 'USD',
            'price' => '120.00000000',
            'as_of' => Carbon::parse('2026-06-12 17:30:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-12 18:00:00', 'UTC'),
            'freshness_status' => 'closed_market',
        ]);
        $holding->update([
            'latest_stock_price_id' => $previousStoredPrice->id,
        ]);
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-11',
            'close' => '118.00000000',
            'adjusted_close' => '118.00000000',
            'currency' => 'USD',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '2.00000000',
            'total_amount' => '200.00',
            'unit_price' => '100.00000000',
            'cash_delta' => '-200.00',
            'balance_after' => '800.00',
            'booked_at' => '2026-06-10 00:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_holdings.0.latest_price', '121.25000000')
            ->assertJsonPath('depot_holdings.0.latest_price_status', 'realtime')
            ->assertJsonPath('depot_holdings.0.latest_price_fetched_at', '2026-06-15T07:23:00+02:00')
            ->assertJsonPath('depot_holdings.0.previous_day_price', '120.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_price_date', '2026-06-12')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '1.04')
            ->assertJsonPath('depot_valuations.latest.stock_balance', '242.50')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1242.50');
    }

    public function test_depot_year_start_price_uses_only_current_open_lots_after_sells(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            Depot::factory()->create([
                'account_balance' => '50000.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'symbol' => 'SEC0',
                'name' => 'iShares MSCI Global Semiconductors UCITS ETF USD Acc',
                'currency' => 'EUR',
            ]);

            $this->actingAs($admin)
                ->postJson('/admin/depot-transactions/stocks', [
                    'type' => 'buy',
                    'stock_holding_id' => $holding->id,
                    'pieces' => '700',
                    'total_amount' => '12670.00',
                    'booked_at' => '2026-06-05',
                ])
                ->assertCreated();

            $this->actingAs($admin)
                ->postJson('/admin/depot-transactions/stocks', [
                    'type' => 'sell',
                    'stock_holding_id' => $holding->id,
                    'pieces' => '700',
                    'total_amount' => '12358.17',
                    'booked_at' => '2026-06-08',
                ])
                ->assertCreated();

            $response = $this->actingAs($admin)
                ->postJson('/admin/depot-transactions/stocks', [
                    'type' => 'buy',
                    'stock_holding_id' => $holding->id,
                    'pieces' => '800',
                    'total_amount' => '14158.40',
                    'booked_at' => '2026-06-10',
                ])
                ->assertCreated()
                ->assertJsonPath('transaction.unit_price', '17.69800000')
                ->assertJsonPath('depot_holdings.0.position_pieces', '800.00000000')
                ->assertJsonPath('depot_holdings.0.year_start_price', '17.69800000');

            $depotHolding = $response->json('depot_holdings.0');
            $this->assertSame('14158.40', number_format(
                ((float) $depotHolding['year_start_price']) * ((float) $depotHolding['position_pieces']),
                2,
                '.',
                '',
            ));
        } finally {
            Carbon::setTestNow();
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
