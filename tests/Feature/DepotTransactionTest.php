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

    public function test_actual_year_stocks_include_realized_and_unrealized_performance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '10000.00',
                'is_active' => true,
            ]);
            $carriedHolding = StockHolding::factory()->create([
                'symbol' => 'ALPHA',
                'name' => 'Alpha Carried Stock',
                'subtitle' => 'Carried position',
                'currency' => 'EUR',
                'latest_price' => '140.000000',
            ]);
            $tradedHolding = StockHolding::factory()->create([
                'symbol' => 'BETA',
                'name' => 'Beta Traded Stock',
                'currency' => 'EUR',
                'latest_price' => '210.000000',
            ]);
            $closedHolding = StockHolding::factory()->create([
                'symbol' => 'GAMMA',
                'name' => 'Gamma Closed Stock',
                'currency' => 'EUR',
                'latest_price' => '60.000000',
            ]);
            $excludedHolding = StockHolding::factory()->create([
                'symbol' => 'OLD',
                'name' => 'Old Closed Stock',
                'currency' => 'EUR',
            ]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $carriedHolding->id,
                'trading_date' => '2025-12-31',
                'close' => '119.00000000',
                'adjusted_close' => '120.00000000',
                'currency' => 'EUR',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $carriedHolding->id,
                'type' => 'buy',
                'pieces' => '10.00000000',
                'total_amount' => '1000.00',
                'unit_price' => '100.00000000',
                'booked_at' => '2025-06-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $carriedHolding->id,
                'type' => 'sell',
                'pieces' => '4.00000000',
                'total_amount' => '600.00',
                'unit_price' => '150.00000000',
                'booked_at' => '2026-04-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $tradedHolding->id,
                'type' => 'buy',
                'pieces' => '5.00000000',
                'total_amount' => '1000.00',
                'unit_price' => '200.00000000',
                'booked_at' => '2026-02-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $tradedHolding->id,
                'type' => 'sell',
                'pieces' => '2.00000000',
                'total_amount' => '440.00',
                'unit_price' => '220.00000000',
                'booked_at' => '2026-05-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $closedHolding->id,
                'type' => 'buy',
                'pieces' => '2.00000000',
                'total_amount' => '100.00',
                'unit_price' => '50.00000000',
                'booked_at' => '2026-03-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $closedHolding->id,
                'type' => 'sell',
                'pieces' => '2.00000000',
                'total_amount' => '90.00',
                'unit_price' => '45.00000000',
                'booked_at' => '2026-06-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $excludedHolding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '100.00',
                'unit_price' => '100.00000000',
                'booked_at' => '2025-01-10 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $excludedHolding->id,
                'type' => 'sell',
                'pieces' => '1.00000000',
                'total_amount' => '100.00',
                'unit_price' => '100.00000000',
                'booked_at' => '2025-02-10 00:00:00',
            ]);

            $response = $this->actingAs($admin)
                ->getJson('/admin/depot-stocks/actual-year')
                ->assertOk()
                ->assertJsonPath('period', 'actual-year')
                ->assertJsonPath('label', 'Actual Year')
                ->assertJsonPath('year', 2026)
                ->assertJsonCount(3, 'stocks');
            $stocks = collect($response->json('stocks'))->keyBy('symbol');

            $this->assertSame('Alpha Carried Stock', $stocks['ALPHA']['name']);
            $this->assertSame('Carried position', $stocks['ALPHA']['subtitle']);
            $this->assertSame('10.00000000', $stocks['ALPHA']['opening_pieces']);
            $this->assertSame('6.00000000', $stocks['ALPHA']['position_pieces']);
            $this->assertSame('120.00000000', $stocks['ALPHA']['average_buy_or_year_start_price']);
            $this->assertSame('144.00000000', $stocks['ALPHA']['average_sell_or_current_price']);
            $this->assertSame('20.00', $stocks['ALPHA']['change_percent']);
            $this->assertSame('240.00', $stocks['ALPHA']['change_amount']);
            $this->assertSame('600.00', $stocks['ALPHA']['traded_volume']);
            $this->assertSame('4.00000000', $stocks['ALPHA']['traded_volume_pieces']);

            $this->assertSame('200.00000000', $stocks['BETA']['average_buy_or_year_start_price']);
            $this->assertSame('214.00000000', $stocks['BETA']['average_sell_or_current_price']);
            $this->assertSame('7.00', $stocks['BETA']['change_percent']);
            $this->assertSame('70.00', $stocks['BETA']['change_amount']);
            $this->assertSame('1440.00', $stocks['BETA']['traded_volume']);
            $this->assertSame('7.00000000', $stocks['BETA']['traded_volume_pieces']);

            $this->assertSame('0.00000000', $stocks['GAMMA']['position_pieces']);
            $this->assertSame('45.00000000', $stocks['GAMMA']['average_sell_or_current_price']);
            $this->assertSame('-10.00', $stocks['GAMMA']['change_percent']);
            $this->assertSame('-10.00', $stocks['GAMMA']['change_amount']);
            $this->assertSame('190.00', $stocks['GAMMA']['traded_volume']);
            $this->assertSame('4.00000000', $stocks['GAMMA']['traded_volume_pieces']);
            $this->assertArrayNotHasKey('OLD', $stocks->all());

            $lastYearResponse = $this->actingAs($admin)
                ->getJson('/admin/depot-stocks/last-year')
                ->assertOk()
                ->assertJsonPath('period', 'last-year')
                ->assertJsonPath('label', 'Last Year')
                ->assertJsonPath('year', 2025)
                ->assertJsonCount(2, 'stocks');
            $lastYearStocks = collect($lastYearResponse->json('stocks'))->keyBy('symbol');

            $this->assertSame('100.00000000', $lastYearStocks['ALPHA']['average_buy_or_year_start_price']);
            $this->assertSame('120.00000000', $lastYearStocks['ALPHA']['average_sell_or_current_price']);
            $this->assertSame('20.00', $lastYearStocks['ALPHA']['change_percent']);
            $this->assertSame('200.00', $lastYearStocks['ALPHA']['change_amount']);
            $this->assertSame('1000.00', $lastYearStocks['ALPHA']['traded_volume']);
            $this->assertSame('10.00000000', $lastYearStocks['ALPHA']['traded_volume_pieces']);
            $this->assertSame('200.00', $lastYearStocks['OLD']['traded_volume']);
            $this->assertSame('2.00000000', $lastYearStocks['OLD']['traded_volume_pieces']);
            $this->assertArrayNotHasKey('BETA', $lastYearStocks->all());

            $fourEverResponse = $this->actingAs($admin)
                ->getJson('/admin/depot-stocks/4-ever')
                ->assertOk()
                ->assertJsonPath('period', '4-ever')
                ->assertJsonPath('label', '4-Ever')
                ->assertJsonPath('year', null)
                ->assertJsonCount(4, 'stocks');
            $fourEverStocks = collect($fourEverResponse->json('stocks'))->keyBy('symbol');

            $this->assertSame('100.00000000', $fourEverStocks['ALPHA']['average_buy_or_year_start_price']);
            $this->assertSame('144.00000000', $fourEverStocks['ALPHA']['average_sell_or_current_price']);
            $this->assertSame('44.00', $fourEverStocks['ALPHA']['change_percent']);
            $this->assertSame('440.00', $fourEverStocks['ALPHA']['change_amount']);
            $this->assertSame('1600.00', $fourEverStocks['ALPHA']['traded_volume']);
            $this->assertSame('14.00000000', $fourEverStocks['ALPHA']['traded_volume_pieces']);
            $this->assertSame('200.00', $fourEverStocks['OLD']['traded_volume']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_actual_year_stocks_are_empty_without_an_active_depot(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/admin/depot-stocks/actual-year')
            ->assertOk()
            ->assertJsonPath('stocks', []);
    }

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
            ->assertJsonPath('transaction.currency', 'EUR')
            ->assertJsonPath('transaction.cash_delta', '250.25')
            ->assertJsonPath('transaction.balance_after', '1250.25')
            ->assertJsonPath('transaction.is_external_cashflow', true)
            ->assertJsonPath('transaction.affects_performance', false);

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
            ->assertJsonPath('transaction.cash_delta', '-100.00')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '0.00')
            ->assertJsonPath('depot_valuations.latest.total_deposits', '250.25')
            ->assertJsonPath('depot_valuations.latest.total_withdrawals', '100.00');

        $this->assertSame('1150.25', $depot->refresh()->account_balance);
    }

    public function test_admin_can_book_stock_linked_dividend_as_performance_cash(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'name' => 'iShares ETF',
            'subtitle' => 'Distributing share class',
            'isin' => 'DE000A0D8Q23',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'dividend',
                'stock_holding_id' => $holding->id,
                'total_amount' => '123.45',
                'booked_at' => '2026-06-15',
                'note' => 'Erträgnisausschüttung DE000A0D8Q23',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'dividend')
            ->assertJsonPath('transaction.stock_holding_id', $holding->id)
            ->assertJsonPath('transaction.stock_label', 'iShares ETF · Distributing share class')
            ->assertJsonPath('transaction.stock_isin', 'DE000A0D8Q23')
            ->assertJsonPath('transaction.cash_delta', '123.45')
            ->assertJsonPath('transaction.is_external_cashflow', false)
            ->assertJsonPath('transaction.affects_performance', true)
            ->assertJsonPath('depot_valuations.latest.opening_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.total_deposits', '0.00')
            ->assertJsonPath('depot_valuations.latest.dividend_amount', '123.45')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '123.45');

        $this->assertSame('1123.45', $depot->refresh()->account_balance);
    }

    public function test_previous_day_performance_excludes_todays_deposit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '13000.00',
                'is_active' => true,
            ]);

            $this->createCashTransaction(
                depot: $depot,
                type: 'deposit',
                amount: '3000.00',
                bookedAt: '2026-08-11 00:00:00',
            );

            $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonPath('depot_valuations.latest.current_balance', '13000.00')
                ->assertJsonPath('depot_valuations.latest.previous_day_balance', '10000.00')
                ->assertJsonPath('depot_valuations.latest.previous_day_external_cash_flow_amount', '3000.00')
                ->assertJsonPath('depot_valuations.latest.previous_day_change_amount', '0.00')
                ->assertJsonPath('depot_valuations.latest.previous_day_change_percent', '0.00');
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_admin_can_list_opening_balance_transaction(): void
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

            $openingBalance = DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '83236.56',
                'unit_price' => null,
                'cash_delta' => '83236.56',
                'balance_after' => '83236.56',
                'booked_at' => '2026-01-01 00:00:00',
                'note' => 'Opening cash balance',
                'is_external_cashflow' => true,
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
                ->assertJsonPath('transactions.2.id', $openingBalance->id)
                ->assertJsonPath('transactions.2.type', 'opening_balance')
                ->assertJsonPath('transactions.2.total_amount', '83236.56')
                ->assertJsonPath('transactions.2.cash_delta', '83236.56')
                ->assertJsonPath('transactions.2.balance_after', '83236.56')
                ->assertJsonPath('transactions.2.note', 'Opening cash balance')
                ->assertJsonPath('depot_valuations.latest.year_start_balance', '83236.56')
                ->assertJsonPath('depot_valuations.latest.current_balance', '57256.56')
                ->assertJsonPath('depot_valuations.latest.balance_change_amount', '-25980.00')
                ->assertJsonPath('depot_valuations.latest.balance_change_percent', '-31.21')
                ->assertJsonPath('depot_valuations.latest.taxable_stock_gain_amount', '0.00')
                ->assertJsonPath('depot_valuations.latest.month_start_balance', '83236.56')
                ->assertJsonPath('depot_valuations.latest.month_change_amount', '-25980.00')
                ->assertJsonPath('depot_valuations.latest.month_change_percent', '-31.21')
                ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '57236.56')
                ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '20.00')
                ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '0.03');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cash_only_movements_adjust_the_year_start_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '84464.35',
                'is_active' => true,
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '83236.56',
                'unit_price' => null,
                'cash_delta' => '83236.56',
                'balance_after' => '83236.56',
                'booked_at' => '2026-01-01 00:00:00',
                'is_external_cashflow' => true,
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'deposit',
                'pieces' => null,
                'total_amount' => '1427.79',
                'unit_price' => null,
                'cash_delta' => '1427.79',
                'balance_after' => '84664.35',
                'booked_at' => '2026-06-15 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'withdrawal',
                'pieces' => null,
                'total_amount' => '200.00',
                'unit_price' => null,
                'cash_delta' => '-200.00',
                'balance_after' => '84464.35',
                'booked_at' => '2026-06-15 01:00:00',
            ]);

            $response = $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonCount(0, 'depot_holdings')
                ->assertJsonPath('depot_valuations.latest.year_start_balance', '84464.35')
                ->assertJsonPath('depot_valuations.latest.current_balance', '84464.35')
                ->assertJsonPath('depot_valuations.latest.balance_change_amount', '0.00')
                ->assertJsonPath('depot_valuations.latest.balance_change_percent', '0.00')
                ->assertJsonPath('depot_valuations.latest.opening_balance', '83236.56')
                ->assertJsonPath('depot_valuations.latest.total_deposits', '1427.79')
                ->assertJsonPath('depot_valuations.latest.total_withdrawals', '200.00')
                ->assertJsonPath('depot_valuations.latest.month_start_balance', '84464.35')
                ->assertJsonPath('depot_valuations.latest.month_change_amount', '0.00')
                ->assertJsonPath('depot_valuations.latest.month_change_percent', '0.00')
                ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '84464.35')
                ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '0.00')
                ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '0.00')
                ->assertJsonPath('depot_valuations.latest.taxable_stock_gain_amount', '0.00');

            $firstPerformancePoint = collect($response->json('depot_performance_series'))->first();

            $this->assertSame('2026-01-01', $firstPerformancePoint['date']);
            $this->assertSame('84464.35', $firstPerformancePoint['cash_balance']);
            $this->assertSame('84464.35', $firstPerformancePoint['account_balance']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_month_start_balance_uses_realtime_price_when_daily_price_is_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '800.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => null,
                'flatex_price' => '120.000000',
            ]);
            $latestRealtimePrice = StockRealtimePrice::factory()->for($holding)->create([
                'price' => '120.00000000',
                'as_of' => '2026-07-03 10:00:00',
                'fetched_at' => '2026-07-03 10:00:00',
            ]);

            $holding->update(['latest_realtime_price_id' => $latestRealtimePrice->id]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-17',
                'close' => '200.00000000',
                'adjusted_close' => '200.00000000',
            ]);
            StockRealtimePrice::factory()->for($holding)->create([
                'price' => '110.00000000',
                'as_of' => '2026-07-01 15:36:00',
                'fetched_at' => '2026-07-02 07:12:08',
                'freshness_status' => 'stale',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '1000.00',
                'unit_price' => null,
                'cash_delta' => '1000.00',
                'balance_after' => '1000.00',
                'booked_at' => '2026-01-01 00:00:00',
                'is_external_cashflow' => true,
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
                'booked_at' => '2026-06-20 00:00:00',
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonPath('depot_valuations.latest.current_balance', '1040.00')
                ->assertJsonPath('depot_valuations.latest.month_start_balance', '1020.00')
                ->assertJsonPath('depot_valuations.latest.month_change_amount', '20.00')
                ->assertJsonPath('depot_valuations.latest.month_change_percent', '1.96');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_one_week_balance_uses_previous_calendar_week_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '700.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '120.000000',
                'flatex_price' => '120.000000',
            ]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-26',
                'close' => '90.00000000',
                'adjusted_close' => '90.00000000',
            ]);
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-29',
                'close' => '110.00000000',
                'adjusted_close' => '110.00000000',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '1000.00',
                'unit_price' => null,
                'cash_delta' => '1000.00',
                'balance_after' => '1000.00',
                'booked_at' => '2026-01-01 00:00:00',
                'is_external_cashflow' => true,
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
                'booked_at' => '2026-06-20 00:00:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => $holding->id,
                'type' => 'buy',
                'pieces' => '1.00000000',
                'total_amount' => '100.00',
                'unit_price' => '100.00000000',
                'cash_delta' => '-100.00',
                'balance_after' => '700.00',
                'booked_at' => '2026-06-28 00:00:00',
            ]);

            $this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->assertJsonPath('depot_valuations.latest.current_balance', '1060.00')
                ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '970.00')
                ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '90.00')
                ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '9.28');
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
                'account_balance' => '955.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => '150.000000',
                'flatex_price' => '150.000000',
            ]);

            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '1000.00',
                'unit_price' => null,
                'cash_delta' => '1000.00',
                'balance_after' => '1000.00',
                'booked_at' => '2026-01-01 00:00:00',
                'is_external_cashflow' => true,
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
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'deposit',
                'pieces' => null,
                'total_amount' => '155.00',
                'unit_price' => null,
                'cash_delta' => '155.00',
                'balance_after' => '500.00',
                'booked_at' => '2026-06-10 00:00:00',
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
                ->assertJsonPath('depot_performance_series.0.cash_balance', '1155.00')
                ->assertJsonPath('depot_performance_series.0.account_balance', '1155.00');

            $series = collect($response->json('depot_performance_series'));
            $februaryFirstPoint = $series->firstWhere('date', '2026-02-01');
            $juneTenthPoint = $series->firstWhere('date', '2026-06-10');
            $latestPoint = $series->last();

            $this->assertSame('240.00', $februaryFirstPoint['stock_balance']);
            $this->assertSame('955.00', $februaryFirstPoint['cash_balance']);
            $this->assertSame('1195.00', $februaryFirstPoint['account_balance']);
            $this->assertSame('955.00', $juneTenthPoint['cash_balance']);
            $this->assertSame('1195.00', $juneTenthPoint['account_balance']);
            $this->assertSame('2026-06-13', $latestPoint['date']);
            $this->assertSame('300.00', $latestPoint['stock_balance']);
            $this->assertSame('1255.00', $latestPoint['account_balance']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_depot_performance_series_uses_each_past_days_latest_realtime_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00', 'Europe/Vienna'));

        try {
            $admin = $this->adminUser();
            $depot = Depot::factory()->create([
                'account_balance' => '800.00',
                'is_active' => true,
            ]);
            $holding = StockHolding::factory()->create([
                'latest_price' => null,
                'flatex_price' => '130.000000',
            ]);
            $latestRealtimePrice = StockRealtimePrice::factory()->for($holding)->create([
                'price' => '130.00000000',
                'as_of' => '2026-07-03 10:00:00',
                'fetched_at' => '2026-07-03 10:00:00',
            ]);

            $holding->update(['latest_realtime_price_id' => $latestRealtimePrice->id]);

            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => '2026-06-17',
                'close' => '50.00000000',
                'adjusted_close' => '50.00000000',
            ]);
            StockRealtimePrice::factory()->for($holding)->create([
                'price' => '100.00000000',
                'as_of' => '2026-06-30 17:35:00',
                'fetched_at' => '2026-06-30 17:36:00',
            ]);
            StockRealtimePrice::factory()->for($holding)->create([
                'price' => '110.00000000',
                'as_of' => '2026-07-01 17:35:00',
                'fetched_at' => '2026-07-01 17:36:00',
            ]);
            StockRealtimePrice::factory()->for($holding)->create([
                'price' => '120.00000000',
                'as_of' => '2026-07-02 17:35:00',
                'fetched_at' => '2026-07-02 17:36:00',
            ]);
            DepotTransaction::factory()->create([
                'depot_id' => $depot->id,
                'stock_holding_id' => null,
                'type' => 'opening_balance',
                'pieces' => null,
                'total_amount' => '1000.00',
                'unit_price' => null,
                'cash_delta' => '1000.00',
                'balance_after' => '1000.00',
                'booked_at' => '2026-01-01 00:00:00',
                'is_external_cashflow' => true,
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
                'booked_at' => '2026-06-20 00:00:00',
            ]);

            $series = collect($this->actingAs($admin)
                ->getJson('/admin/depot-transactions')
                ->assertOk()
                ->json('depot_performance_series'));

            $juneThirtiethPoint = $series->firstWhere('date', '2026-06-30');
            $julyFirstPoint = $series->firstWhere('date', '2026-07-01');
            $julySecondPoint = $series->firstWhere('date', '2026-07-02');
            $latestPoint = $series->last();

            $this->assertSame('200.00', $juneThirtiethPoint['stock_balance']);
            $this->assertSame('1000.00', $juneThirtiethPoint['account_balance']);
            $this->assertSame('220.00', $julyFirstPoint['stock_balance']);
            $this->assertSame('1020.00', $julyFirstPoint['account_balance']);
            $this->assertSame('240.00', $julySecondPoint['stock_balance']);
            $this->assertSame('1040.00', $julySecondPoint['account_balance']);
            $this->assertSame('2026-07-03', $latestPoint['date']);
            $this->assertSame('260.00', $latestPoint['stock_balance']);
            $this->assertSame('1060.00', $latestPoint['account_balance']);
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
            'type' => 'opening_balance',
            'pieces' => null,
            'total_amount' => '1000.00',
            'unit_price' => null,
            'cash_delta' => '1000.00',
            'balance_after' => '1000.00',
            'booked_at' => '2026-01-01 00:00:00',
            'is_external_cashflow' => true,
        ]);
        $otherDepot = Depot::factory()->create([
            'is_active' => false,
        ]);
        $ownedHolding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'subtitle' => 'Core technology holding',
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
            ->assertJsonPath('depot_holdings.0.subtitle', 'Core technology holding')
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
            ->assertJsonPath('depot_valuations.latest.previous_day_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.previous_day_change_amount', '188.75')
            ->assertJsonPath('depot_valuations.latest.previous_day_change_percent', '18.88')
            ->assertJsonPath('depot_valuations.latest.year_start_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1188.75')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '188.75')
            ->assertJsonPath('depot_valuations.latest.balance_change_percent', '18.88')
            ->assertJsonPath('depot_valuations.latest.taxable_stock_gain_amount', '178.75')
            ->assertJsonPath('depot_valuations.latest.one_week_start_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.one_week_change_amount', '188.75')
            ->assertJsonPath('depot_valuations.latest.one_week_change_percent', '18.88');

        $this->assertSame(['AAPL'], collect($response->json('depot_holdings'))->pluck('symbol')->all());
        $this->assertSame('710.00', $depot->refresh()->account_balance);
    }

    public function test_depot_taxable_stock_gain_uses_net_open_holding_gain(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '800.00',
            'is_active' => true,
        ]);
        $winningHolding = StockHolding::factory()->create([
            'latest_price' => '150.000000',
            'flatex_price' => '150.000000',
        ]);
        $losingHolding = StockHolding::factory()->create([
            'latest_price' => '50.000000',
            'flatex_price' => '50.000000',
        ]);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => 'opening_balance',
            'pieces' => null,
            'total_amount' => '1000.00',
            'unit_price' => null,
            'cash_delta' => '1000.00',
            'balance_after' => '1000.00',
            'booked_at' => '2026-01-01 00:00:00',
            'is_external_cashflow' => true,
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $winningHolding->id,
            'type' => 'buy',
            'pieces' => '1.00000000',
            'total_amount' => '100.00',
            'unit_price' => '100.00000000',
            'cash_delta' => '-100.00',
            'balance_after' => '900.00',
            'booked_at' => '2026-06-10 00:00:00',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $losingHolding->id,
            'type' => 'buy',
            'pieces' => '1.00000000',
            'total_amount' => '100.00',
            'unit_price' => '100.00000000',
            'cash_delta' => '-100.00',
            'balance_after' => '800.00',
            'booked_at' => '2026-06-10 00:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_valuations.latest.stock_balance', '200.00')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '0.00')
            ->assertJsonPath('depot_valuations.latest.taxable_stock_gain_amount', '0.00');
    }

    public function test_depot_profit_infers_opening_cash_when_no_opening_balance_transaction_exists(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '700.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'latest_price' => '110.000000',
            'flatex_price' => '110.000000',
        ]);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '3.00000000',
            'total_amount' => '300.00',
            'unit_price' => '100.00000000',
            'cash_delta' => '-300.00',
            'balance_after' => '700.00',
            'booked_at' => '2026-06-10 00:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_valuations.latest.cash_balance', '700.00')
            ->assertJsonPath('depot_valuations.latest.stock_balance', '330.00')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1030.00')
            ->assertJsonPath('depot_valuations.latest.opening_balance', '1000.00')
            ->assertJsonPath('depot_valuations.latest.balance_change_amount', '30.00')
            ->assertJsonPath('depot_valuations.latest.balance_change_percent', '3.00');
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
            ->assertJsonPath('depot_holdings.0.previous_day_price', '118.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_price_date', '2026-06-11')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '2.75')
            ->assertJsonPath('depot_valuations.latest.stock_balance', '242.50')
            ->assertJsonPath('depot_valuations.latest.current_balance', '1242.50');
    }

    public function test_depot_holding_payload_uses_daily_close_for_previous_day_price(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'currency' => 'USD',
            'latest_price' => '191.500000',
            'latest_price_fetched_at' => Carbon::parse('2026-06-15 08:00:00', 'UTC'),
        ]);
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-12',
            'close' => '188.00000000',
            'adjusted_close' => '180.00000000',
            'currency' => 'USD',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '2.50000000',
            'total_amount' => '300.00',
            'unit_price' => '120.00000000',
            'cash_delta' => '-300.00',
            'balance_after' => '700.00',
            'booked_at' => '2026-06-10 00:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_holdings.0.previous_day_price', '188.00000000')
            ->assertJsonPath('depot_holdings.0.previous_day_price_date', '2026-06-12')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '1.86');
    }

    public function test_depot_holding_payload_prefers_official_eod_over_realtime_and_stale_daily_price(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $holding = StockHolding::factory()->create([
            'symbol' => 'CEBS',
            'currency' => 'EUR',
            'isin' => 'IE00063FT9K6',
            'latest_price' => '9.796000',
        ]);
        $latestRealtimePrice = StockRealtimePrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'symbol' => 'CEBS',
            'currency' => 'EUR',
            'price' => '9.79600000',
            'as_of' => Carbon::parse('2026-08-07 08:03:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-08-07 08:03:10', 'UTC'),
            'freshness_status' => 'fresh',
        ]);
        $holding->update([
            'latest_realtime_price_id' => $latestRealtimePrice->id,
        ]);
        StockRealtimePrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'symbol' => 'CEBS',
            'currency' => 'EUR',
            'price' => '9.84300000',
            'as_of' => Carbon::parse('2026-08-06 15:36:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-08-06 15:36:10', 'UTC'),
            'freshness_status' => 'stale',
        ]);
        StockPrice::factory()->create([
            'instrument_key' => 'isin:IE00063FT9K6',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'symbol' => 'CEBS',
            'isin' => 'IE00063FT9K6',
            'currency' => 'EUR',
            'price' => '9.78100000',
            'close' => '9.78100000',
            'price_type' => 'historical_eod',
            'as_of' => Carbon::parse('2026-08-06', 'Europe/Vienna')->endOfDay()->utc(),
            'fetched_at' => Carbon::parse('2026-08-07 06:00:00', 'UTC'),
            'freshness_status' => 'historical',
        ]);
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-17',
            'close' => '10.16400000',
            'adjusted_close' => '10.16400000',
            'currency' => 'EUR',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '1000.00000000',
            'total_amount' => '8931.00',
            'unit_price' => '8.93100000',
            'cash_delta' => '-8931.00',
            'balance_after' => '1000.00',
            'booked_at' => '2026-06-24 00:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_holdings.0.latest_price', '9.79600000')
            ->assertJsonPath('depot_holdings.0.previous_day_price', '9.78100000')
            ->assertJsonPath('depot_holdings.0.previous_day_price_date', '2026-08-06')
            ->assertJsonPath('depot_holdings.0.previous_day_change_percent', '0.15');
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

    public function test_depot_profit_formula_excludes_external_cashflows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();

            $this->assertDepotValuation($admin, '22000.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
            ], [
                'opening_balance' => '20000.00',
                'total_deposits' => '0.00',
                'total_withdrawals' => '0.00',
                'balance_change_amount' => '2000.00',
                'balance_change_with_broker_bonus_amount' => '2000.00',
            ]);

            $this->assertDepotValuation($admin, '27000.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'deposit', 'amount' => '5000.00', 'booked_at' => '2026-03-15 00:00:00'],
            ], [
                'opening_balance' => '20000.00',
                'total_deposits' => '5000.00',
                'total_withdrawals' => '0.00',
                'balance_change_amount' => '2000.00',
            ]);

            $this->assertDepotValuation($admin, '19000.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'withdrawal', 'amount' => '3000.00', 'booked_at' => '2026-08-20 00:00:00'],
            ], [
                'opening_balance' => '20000.00',
                'total_deposits' => '0.00',
                'total_withdrawals' => '3000.00',
                'balance_change_amount' => '2000.00',
            ]);

            $this->assertDepotValuation($admin, '29000.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'deposit', 'amount' => '5000.00', 'booked_at' => '2026-03-15 00:00:00'],
                ['type' => 'withdrawal', 'amount' => '2000.00', 'booked_at' => '2026-08-20 00:00:00'],
            ], [
                'opening_balance' => '20000.00',
                'total_deposits' => '5000.00',
                'total_withdrawals' => '2000.00',
                'balance_change_amount' => '6000.00',
                'balance_change_percent' => '26.09',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_depot_reports_income_costs_and_broker_bonus_separately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 12:00:00', 'UTC'));

        try {
            $admin = $this->adminUser();

            $this->assertDepotValuation($admin, '20105.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'dividend', 'amount' => '100.00', 'booked_at' => '2026-04-12 00:00:00'],
                ['type' => 'interest', 'amount' => '5.00', 'booked_at' => '2026-04-30 00:00:00'],
            ], [
                'balance_change_amount' => '105.00',
                'total_deposits' => '0.00',
                'dividend_amount' => '100.00',
                'interest_amount' => '5.00',
            ]);

            $this->assertDepotValuation($admin, '19964.60', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'fee', 'amount' => '7.90', 'booked_at' => '2026-05-02 00:00:00'],
                ['type' => 'tax', 'amount' => '27.50', 'booked_at' => '2026-05-03 00:00:00'],
            ], [
                'balance_change_amount' => '-35.40',
                'fee_amount' => '7.90',
                'tax_amount' => '27.50',
            ]);

            $this->assertDepotValuation($admin, '20100.00', [
                ['type' => 'opening_balance', 'amount' => '20000.00', 'booked_at' => '2026-01-01 00:00:00'],
                ['type' => 'broker_bonus', 'amount' => '100.00', 'booked_at' => '2026-02-01 00:00:00'],
            ], [
                'balance_change_amount' => '0.00',
                'balance_change_with_broker_bonus_amount' => '100.00',
                'broker_bonus_amount' => '100.00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<int, array{type: string, amount: string, booked_at: string}>  $transactions
     * @param  array<string, string>  $expectations
     */
    private function assertDepotValuation(User $admin, string $currentPortfolioValue, array $transactions, array $expectations): void
    {
        Depot::query()->update(['is_active' => false]);

        $depot = Depot::factory()->create([
            'account_balance' => $currentPortfolioValue,
            'is_active' => true,
        ]);

        foreach ($transactions as $transaction) {
            $this->createCashTransaction(
                depot: $depot,
                type: $transaction['type'],
                amount: $transaction['amount'],
                bookedAt: $transaction['booked_at'],
            );
        }

        $response = $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonPath('depot_valuations.latest.current_balance', $currentPortfolioValue);

        foreach ($expectations as $key => $expectedValue) {
            $response->assertJsonPath("depot_valuations.latest.{$key}", $expectedValue);
        }
    }

    private function createCashTransaction(Depot $depot, string $type, string $amount, string $bookedAt): DepotTransaction
    {
        $absoluteAmount = abs((float) $amount);
        $cashDelta = in_array($type, DepotTransaction::NegativeCashDeltaTypes, true)
            ? -$absoluteAmount
            : $absoluteAmount;

        return DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => $type,
            'pieces' => null,
            'total_amount' => number_format($absoluteAmount, 2, '.', ''),
            'unit_price' => null,
            'cash_delta' => number_format($cashDelta, 2, '.', ''),
            'balance_after' => $depot->account_balance,
            'booked_at' => $bookedAt,
            'is_external_cashflow' => in_array($type, DepotTransaction::ExternalCashflowTypes, true),
            'affects_performance' => in_array($type, DepotTransaction::PerformanceCashTypes, true),
        ]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
