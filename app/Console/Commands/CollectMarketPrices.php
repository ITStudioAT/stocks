<?php

namespace App\Console\Commands;

use App\Services\MarketDataCoordinator;
use App\Services\MarketPriceStore;
use Illuminate\Console\Command;

class CollectMarketPrices extends Command
{
    protected $signature = 'market-data:collect';

    protected $description = 'Collect saved listings in venue sessions with a five-minute provider gate';

    public function handle(MarketDataCoordinator $coordinator): int
    {
        if (! MarketPriceStore::enabled()) {
            $this->error('Central market data is not enabled.');

            return self::FAILURE;
        }
        $result = $coordinator->collect();
        $coordinator->prepareReads();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
