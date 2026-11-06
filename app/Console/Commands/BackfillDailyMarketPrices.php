<?php

namespace App\Console\Commands;

use App\Models\StockHolding;
use App\Services\FreeDailyMarketData;
use App\Services\MarketIdentityRegistry;
use App\Services\MarketPriceStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillDailyMarketPrices extends Command
{
    protected $signature = 'market-data:daily {holding? : Optional saved holding ID} {--days=365} {--limit=3}';

    protected $description = 'Load free daily history into central prices using verified mappings and provider cooldowns';

    public function handle(FreeDailyMarketData $daily, MarketIdentityRegistry $identities): int
    {
        if (! MarketPriceStore::enabled()) {
            $this->error('Central market data is not enabled.');

            return self::FAILURE;
        }
        $holdings = StockHolding::query()->when($this->argument('holding'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        $checked = [];
        $attempts = 0;
        $failed = false;
        foreach ($holdings as $holding) {
            $listing = $identities->listing($holding);
            if (isset($checked[$listing->id]) || (! $this->argument('holding') && Cache::has('market-data.daily.checked.'.$listing->id))) {
                continue;
            }
            $checked[$listing->id] = true;
            $result = $daily->import($holding, (int) $this->option('days'));
            $this->line($holding->symbol.' '.json_encode($result, JSON_THROW_ON_ERROR));
            $failed = $failed || $result['status'] === 'failed';
            if (! in_array($result['status'], ['unsupported', 'cooldown'], true) && ++$attempts >= max(1, (int) $this->option('limit'))) {
                break;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
