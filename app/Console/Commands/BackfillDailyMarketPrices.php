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
    protected $signature = 'market-data:daily {holding? : Optional saved holding ID} {--days= : Override the default one-year window} {--limit=3} {--initialized-only : Only refresh listings with stored free daily history} {--fill-gaps : Only import days without a usable free price}';

    protected $description = 'Load free daily history into central prices using verified mappings and provider cooldowns';

    public function handle(FreeDailyMarketData $daily, MarketIdentityRegistry $identities): int
    {
        if (! MarketPriceStore::enabled()) {
            $this->error('Central market data is not enabled.');

            return self::FAILURE;
        }
        $holdings = StockHolding::query()->when($this->argument('holding'), fn ($query, $id) => $query->whereKey($id))
            ->when($this->option('initialized-only'), fn ($query) => $query->whereExists(fn ($prices) => $prices
                ->selectRaw('1')->from('market_prices')->whereColumn('market_prices.market_listing_id', 'stock_holdings.market_listing_id')
                ->where('source_key', 'yahoo_daily')->where('interval_code', '1d')->where('is_current', true)->where('validation_status', 'valid')))
            ->orderBy('id')->get();
        $checked = [];
        $attempts = 0;
        $failed = false;
        $days = $this->option('days');
        $fillGaps = (bool) $this->option('fill-gaps');
        foreach ($holdings as $holding) {
            $listing = $identities->listing($holding);
            if (isset($checked[$listing->id]) || (! $this->argument('holding') && Cache::has('market-data.daily.checked.'.$listing->id))) {
                continue;
            }
            $checked[$listing->id] = true;
            $result = $daily->import($holding, $days === null ? null : (int) $days, $fillGaps);
            $this->line($holding->symbol.' '.json_encode($result, JSON_THROW_ON_ERROR));
            $failed = $failed || $result['status'] === 'failed';
            if (! in_array($result['status'], ['unsupported', 'cooldown', 'up_to_date'], true) && ++$attempts >= max(1, (int) $this->option('limit'))) {
                break;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
