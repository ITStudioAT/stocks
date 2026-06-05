<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'deposit',
                'total_amount' => '500.00',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'withdrawal',
                'total_amount' => '100.00',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson('/admin/depot-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('transactions.0.type', 'withdrawal')
            ->assertJsonPath('transactions.0.cash_delta', '-100.00')
            ->assertJsonPath('transactions.0.balance_after', '400.00')
            ->assertJsonPath('transactions.1.type', 'deposit')
            ->assertJsonPath('transactions.1.cash_delta', '500.00')
            ->assertJsonPath('transactions.1.balance_after', '500.00');
    }

    public function test_admin_can_list_opening_cash_deposit_transaction(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '56956.56',
            'is_active' => true,
            'created_at' => '2026-06-03 14:26:50',
        ]);
        $holding = StockHolding::factory()->create();

        $deposit = DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => null,
            'type' => 'deposit',
            'pieces' => null,
            'total_amount' => '83236.56',
            'unit_price' => null,
            'cash_delta' => '83236.56',
            'balance_after' => '83236.56',
            'booked_at' => '2026-06-03 14:26:50',
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
            'balance_after' => '56956.56',
            'booked_at' => '2026-06-04 18:08:17',
        ]);

        DepotTransaction::factory()->create([
            'depot_id' => $depot->id,
            'stock_holding_id' => $holding->id,
            'type' => 'buy',
            'pieces' => '1.00000000',
            'total_amount' => '13020.00',
            'unit_price' => '13020.00000000',
            'cash_delta' => '-13020.00',
            'balance_after' => '70216.56',
            'booked_at' => '2026-06-04 18:07:41',
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
            ->assertJsonPath('transactions.2.note', 'Opening cash balance');
    }

    public function test_admin_can_list_current_stock_positions_for_the_active_depot(): void
    {
        $admin = $this->adminUser();
        $depot = Depot::factory()->create([
            'account_balance' => '1000.00',
            'is_active' => true,
        ]);
        $otherDepot = Depot::factory()->create([
            'is_active' => false,
        ]);
        $ownedHolding = StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'currency' => 'USD',
            'latest_price' => '191.500000',
            'flatex_price' => '191.500000',
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
            ->assertJsonPath('depot_holdings.0.latest_price', '191.500000')
            ->assertJsonPath('depot_holdings.0.flatex_price', '191.500000')
            ->assertJsonPath('depot_holdings.0.year_start_price', '120.00000000')
            ->assertJsonPath('depot_holdings.0.currency', 'USD')
            ->assertJsonPath('depot_holdings.0.position_pieces', '2.50000000');

        $this->assertSame(['AAPL'], collect($response->json('depot_holdings'))->pluck('symbol')->all());
        $this->assertSame('710.00', $depot->refresh()->account_balance);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
