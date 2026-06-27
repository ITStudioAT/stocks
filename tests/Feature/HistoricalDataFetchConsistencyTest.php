<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class HistoricalDataFetchConsistencyTest extends TestCase
{
    public function test_stock_historical_data_fetching_uses_the_single_eodhd_historical_service(): void
    {
        $this->assertFileDoesNotExist(app_path('Jobs/FetchStockHistoricalPrices.php'));
        $this->assertFileDoesNotExist(app_path('Jobs/FetchHistoricalSessionPrices.php'));
        $this->assertFileDoesNotExist(app_path('Services/StockHistoricalDailyPriceFetcher.php'));
        $this->assertFileDoesNotExist(app_path('Services/HistoricalSessionPriceScheduler.php'));
        $this->assertFileDoesNotExist(app_path('Services/HistoricalSessionStartPriceFetchStatus.php'));
        $this->assertFileDoesNotExist(app_path('Models/StockHistoricalPriceFetchRun.php'));
        $this->assertFileDoesNotExist(app_path('Models/StockHistoricalPriceFetchItem.php'));
        $this->assertFileDoesNotExist(database_path('factories/StockHistoricalPriceFetchRunFactory.php'));
        $this->assertFileDoesNotExist(database_path('factories/StockHistoricalPriceFetchItemFactory.php'));

        $this->assertSame([], $this->forbiddenHistoricalFetcherReferences());
    }

    /**
     * @return array<int, string>
     */
    private function forbiddenHistoricalFetcherReferences(): array
    {
        $forbidden = [
            'FetchStockHistoricalPrices',
            'FetchHistoricalSessionPrices',
            'StockHistoricalDailyPriceFetcher',
            'HistoricalSessionPriceScheduler',
            'HistoricalSessionStartPriceFetchStatus',
            'StockHistoricalPriceFetchRun',
            'StockHistoricalPriceFetchItem',
            'historical-session-prices:dispatch-due',
        ];
        $matches = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $matches[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).": {$needle}";
                }
            }
        }

        return $matches;
    }
}
