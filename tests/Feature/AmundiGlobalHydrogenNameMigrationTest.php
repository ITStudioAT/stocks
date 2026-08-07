<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmundiGlobalHydrogenNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_the_renamed_fund_without_changing_other_holdings(): void
    {
        $hydrogenEtf = StockHolding::factory()->create([
            'isin' => 'FR0010930644',
            'name' => 'Amundi ETF MSCI Europe Energy UCITS ETF',
        ]);
        $otherHolding = StockHolding::factory()->create([
            'isin' => 'DE000A0D8Q23',
            'name' => 'iShares ATX UCITS ETF',
        ]);

        $migration = require database_path('migrations/2026_08_07_074708_backfill_amundi_global_hydrogen_name.php');
        $migration->up();

        $this->assertSame('Amundi Global Hydrogen UCITS ETF Acc', $hydrogenEtf->refresh()->name);
        $this->assertSame('iShares ATX UCITS ETF', $otherHolding->refresh()->name);
    }
}
