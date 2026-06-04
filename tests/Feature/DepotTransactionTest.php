<?php

namespace Tests\Feature;

use App\Models\Depot;
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
                'note' => 'Funding',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Cash transaction booked.')
            ->assertJsonPath('depot.account_balance', '1250.25')
            ->assertJsonPath('transaction.type', 'deposit')
            ->assertJsonPath('transaction.total_amount', '250.25')
            ->assertJsonPath('transaction.cash_delta', '250.25')
            ->assertJsonPath('transaction.balance_after', '1250.25');

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/cash', [
                'type' => 'withdrawal',
                'total_amount' => '100.00',
            ])
            ->assertCreated()
            ->assertJsonPath('depot.account_balance', '1150.25')
            ->assertJsonPath('transaction.cash_delta', '-100.00');

        $this->assertSame('1150.25', $depot->refresh()->account_balance);
        $this->assertDatabaseHas('depot_transactions', [
            'depot_id' => $depot->id,
            'type' => 'deposit',
            'total_amount' => '250.25',
            'balance_after' => '1250.25',
            'note' => 'Funding',
        ]);
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

        $this->actingAs($admin)
            ->postJson('/admin/depot-transactions/stocks', [
                'type' => 'buy',
                'stock_holding_id' => $holding->id,
                'pieces' => '2.5',
                'total_amount' => '500.00',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Stock transaction booked.')
            ->assertJsonPath('depot.account_balance', '500.00')
            ->assertJsonPath('transaction.type', 'buy')
            ->assertJsonPath('transaction.pieces', '2.50000000')
            ->assertJsonPath('transaction.unit_price', '200.00000000')
            ->assertJsonPath('transaction.cash_delta', '-500.00');

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

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
