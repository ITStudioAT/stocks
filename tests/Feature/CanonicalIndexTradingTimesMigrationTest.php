<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalIndexTradingTimesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_supported_indices_without_changing_unknown_indices(): void
    {
        $atx = IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 00:00:00-23:59:00 UTC',
        ]);
        $nasdaq = IndexWatchItem::factory()->create([
            'symbol' => 'NDX',
            'trading_times' => null,
        ]);
        $unknown = IndexWatchItem::factory()->create([
            'symbol' => 'UNKNOWN',
            'trading_times' => 'Monday-Friday 08:00:00-16:00:00 UTC',
        ]);

        $migration = require database_path('migrations/2026_08_06_203057_backfill_canonical_index_trading_times.php');
        $migration->up();

        $this->assertSame('Monday-Friday 09:00:00-17:30:00 Europe/Vienna', $atx->refresh()->trading_times);
        $this->assertSame('Monday-Friday 09:30:00-16:00:00 America/New_York', $nasdaq->refresh()->trading_times);
        $this->assertSame('Monday-Friday 08:00:00-16:00:00 UTC', $unknown->refresh()->trading_times);
    }
}
