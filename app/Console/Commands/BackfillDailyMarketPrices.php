<?php

namespace App\Console\Commands;

use App\Models\StockHolding;
use App\Services\FreeDailyMarketData;
use App\Services\FreeTradingCalendar;
use App\Services\MarketIdentityRegistry;
use App\Services\MarketPriceStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BackfillDailyMarketPrices extends Command
{
    protected $signature = 'market-data:daily {holding? : Optional saved holding ID} {--days= : Override the default one-year window} {--limit=3} {--initialized-only : Only refresh listings with stored free daily history} {--fill-gaps : Only import days without a usable free price} {--missing-settled : Refresh listings missing the latest completed daily bar}';

    protected $description = 'Load free daily history into central prices using verified mappings and provider cooldowns';

    public function handle(FreeDailyMarketData $daily, MarketIdentityRegistry $identities, FreeTradingCalendar $calendar): int
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
        $missingSettled = (bool) $this->option('missing-settled');
        foreach ($holdings as $holding) {
            $listing = $identities->listing($holding);
            $cacheKey = $missingSettled ? 'market-data.daily.settled.checked.'.$listing->id : 'market-data.daily.checked.'.$listing->id;
            if (isset($checked[$listing->id]) || (! $this->argument('holding') && Cache::has($cacheKey))) {
                continue;
            }
            $checked[$listing->id] = true;
            if ($missingSettled && ! $this->missingSettledBar($holding, $listing->id, $calendar)) {
                continue;
            }
            $result = $daily->import($holding, $days === null ? null : (int) $days, $fillGaps);
            if ($missingSettled && ($result['status'] === 'failed' || ($result['missing_count'] ?? 0) > 0)) {
                Cache::put($cacheKey, true, now()->addMinutes(30));
            }
            $this->line($holding->symbol.' '.json_encode($result, JSON_THROW_ON_ERROR));
            $failed = $failed || $result['status'] === 'failed';
            if (! in_array($result['status'], ['unsupported', 'cooldown', 'up_to_date'], true) && ++$attempts >= max(1, (int) $this->option('limit'))) {
                break;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function missingSettledBar(StockHolding $holding, int $listingId, FreeTradingCalendar $calendar): bool
    {
        $timezone = $calendar->timezone($holding);
        if ($timezone === null || $calendar->shouldCollect($holding)) {
            return false;
        }
        $today = now($timezone);
        $date = $calendar->sessionHasSettled($holding, $today->toDateString())
            ? $today->toDateString()
            : $calendar->previousDay($holding, $today)?->toDateString();
        if ($date === null || ! $calendar->sessionHasSettled($holding, $date)) {
            return false;
        }

        return ! DB::table('market_prices')->where('market_listing_id', $listingId)
            ->where('currency', $holding->currency)->where('interval_code', '1d')->where('is_current', true)
            ->where('validation_status', 'valid')->where('close', '>', 0)->where('trading_date', '>=', $date)->exists();
    }
}
