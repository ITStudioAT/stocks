<?php

namespace Tests\Feature;

use App\Jobs\CheckPerformanceConsistency;
use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\User;
use App\Services\DashboardPerformanceSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PerformanceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_and_rebuild_report_missing_and_wrong_periods(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        Queue::fake();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $depot = Depot::factory()->create(['created_at' => '2025-12-01 10:00:00', 'account_balance' => 0]);
        DB::table('depot_performance_snapshots')->insert([
            'depot_id' => $depot->id, 'period' => 'month', 'end_date' => '2025-12-31',
            'payload' => json_encode(['period' => 'month', 'change_amount' => '999.00', 'change_percent' => '999.00', 'source_markers' => []]),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/data/performance-consistency', ['repair' => true])->assertOk();
        Queue::assertPushed(CheckPerformanceConsistency::class, 1);
        $id = $response->json('run.id');
        $this->postJson('/admin/data/performance-consistency', ['repair' => true])->assertJsonPath('run.id', $id);
        Queue::assertPushed(CheckPerformanceConsistency::class, 1);

        (new CheckPerformanceConsistency($id))->handle(app(DashboardPerformanceSnapshots::class));
        $run = $this->getJson('/admin/data/performance-consistency')->assertOk()->json('run');
        $this->assertSame('finished', $run['status']);
        $this->assertGreaterThan(0, $run['missing']);
        $this->assertSame(1, $run['incorrect']);
        $this->assertGreaterThan(0, $run['repaired']);
        $stored = json_decode(DB::table('depot_performance_snapshots')->where('depot_id', $depot->id)->where('period', 'month')->where('end_date', '2025-12-31')->value('payload'), true);
        $this->assertNotSame('999.00', $stored['change_amount']);
    }

    public function test_guest_cannot_start_or_read_a_run(): void
    {
        $this->getJson('/admin/data/performance-consistency')->assertUnauthorized();
        $this->postJson('/admin/data/performance-consistency', ['repair' => false])->assertUnauthorized();
    }

    public function test_fresh_calculation_matches_dashboard_period_calculation(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $depot = Depot::factory()->create(['account_balance' => 1200]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id, 'stock_holding_id' => null, 'type' => 'opening_balance',
            'cash_delta' => 1000, 'balance_after' => 1000, 'booked_at' => '2025-12-01 12:00:00',
        ]);
        DepotTransaction::factory()->create([
            'depot_id' => $depot->id, 'stock_holding_id' => null, 'type' => 'deposit',
            'cash_delta' => 200, 'balance_after' => 1200, 'booked_at' => '2026-01-15 12:00:00',
        ]);
        $snapshots = app(DashboardPerformanceSnapshots::class);
        $end = Carbon::parse('2026-01-31');
        $this->assertSame($snapshots->completedPeriod($depot, 'month', $end), $snapshots->calculatePeriodFresh($depot, 'month', $end));
    }

    public function test_check_does_not_change_an_incorrect_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        Queue::fake();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $depot = Depot::factory()->create(['created_at' => '2026-01-01 10:00:00']);
        $payload = json_encode(['period' => 'month', 'change_amount' => '999.00']);
        DB::table('depot_performance_snapshots')->insert([
            'depot_id' => $depot->id, 'period' => 'month', 'end_date' => '2026-01-31', 'payload' => $payload,
        ]);
        $id = $this->actingAs($admin)->postJson('/admin/data/performance-consistency', ['repair' => false])->json('run.id');
        (new CheckPerformanceConsistency($id))->handle(app(DashboardPerformanceSnapshots::class));
        $this->getJson('/admin/data/performance-consistency')->assertJsonPath('run.incorrect', 1)->assertJsonPath('run.repaired', 0);
        $this->getJson('/admin/data/performance-consistency')->assertJsonPath('run.periods.month.incorrect', 1)->assertJsonPath('run.issues.0.reason', 'missing');
        $this->assertSame($payload, DB::table('depot_performance_snapshots')->where('depot_id', $depot->id)->where('period', 'month')->value('payload'));
    }

    public function test_equal_values_in_a_different_json_key_order_are_not_reported_as_incorrect(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        Queue::fake();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $depot = Depot::factory()->create(['created_at' => '2026-01-01 10:00:00', 'account_balance' => 0]);
        $snapshots = app(DashboardPerformanceSnapshots::class);
        $fresh = $snapshots->calculatePeriodFresh($depot, 'month', Carbon::parse('2026-01-31'));
        DB::table('depot_performance_snapshots')->insert([
            'depot_id' => $depot->id, 'period' => 'month', 'end_date' => '2026-01-31',
            'payload' => json_encode(array_reverse($fresh, true)),
        ]);
        $id = $this->actingAs($admin)->postJson('/admin/data/performance-consistency', ['repair' => false])->json('run.id');
        (new CheckPerformanceConsistency($id))->handle($snapshots);
        $this->getJson('/admin/data/performance-consistency')->assertJsonPath('run.periods.month.incorrect', 0);
    }
}
