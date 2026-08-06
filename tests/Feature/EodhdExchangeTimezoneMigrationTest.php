<?php

namespace Tests\Feature;

use App\Models\EodhdExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EodhdExchangeTimezoneMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_missing_timezone_from_exchange_details(): void
    {
        $exchange = EodhdExchange::query()->create([
            'code' => 'MC',
            'detail_code' => 'XMAD',
            'timezone' => null,
            'raw_details' => ['Timezone' => 'Europe/Madrid'],
        ]);
        $existingTimezone = EodhdExchange::query()->create([
            'code' => 'VI',
            'detail_code' => 'XWBO',
            'timezone' => 'Europe/Vienna',
            'raw_details' => ['Timezone' => 'UTC'],
        ]);

        $migration = require database_path('migrations/2026_08_06_203507_backfill_eodhd_exchange_timezones_from_details.php');
        $migration->up();

        $this->assertSame('Europe/Madrid', $exchange->refresh()->timezone);
        $this->assertSame('Europe/Vienna', $existingTimezone->refresh()->timezone);
    }
}
