<?php

namespace App\Console\Commands;

use App\Models\MarketPrice;
use App\Services\LocalMarketDataDatabase;
use App\Services\MarketPriceLegacyImporter;
use Illuminate\Console\Command;

class ImportMarketPrices extends Command
{
    protected $signature = 'market-data:import-legacy {--chunk=300}';

    protected $description = 'Resume a lossless legacy market-data import without provider requests or deleting old tables';

    public function handle(MarketPriceLegacyImporter $importer): int
    {
        app(LocalMarketDataDatabase::class)->assertLocal();
        $before = MarketPrice::query()->count();
        $report = $importer->run(max(1, min(1000, (int) $this->option('chunk'))));
        $this->line(json_encode(['tables' => $report, 'new_price_rows' => MarketPrice::query()->count() - $before], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
