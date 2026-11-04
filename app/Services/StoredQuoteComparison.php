<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockRealtimePrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StoredQuoteComparison
{
    /** @param array<string, mixed> $result @param Collection<int, StockHolding> $holdings @return array<string, mixed> */
    public function attach(array $result, Collection $holdings): array
    {
        $selected = StockHolding::query()->whereKey($holdings->modelKeys())
            ->select('id', 'isin')
            ->addSelect(['quote_id' => StockRealtimePrice::query()->select('id')
                ->whereColumn('stock_holding_id', 'stock_holdings.id')->where('validation_status', 'valid')
                ->where('price', '>', 0)->whereNotNull('as_of')->where('as_of', '<=', now('UTC'))
                ->orderByDesc('as_of')->orderByDesc('id')->limit(1),
                'daily_id' => StockHoldingDailyPrice::query()->select('id')
                    ->whereColumn('stock_holding_id', 'stock_holdings.id')->where('close', '>', 0)
                    ->where('trading_date', '<=', now('UTC')->toDateString())
                    ->orderByDesc('trading_date')->orderByDesc('id')->limit(1),
            ])->get()->keyBy('isin');
        $quotes = StockRealtimePrice::query()->whereKey($selected->pluck('quote_id')->filter())->get()->keyBy('id');
        $daily = StockHoldingDailyPrice::query()->whereKey($selected->pluck('daily_id')->filter())->get()->keyBy('id');
        $result['instruments'] = array_map(function (array $row) use ($selected, $quotes, $daily): array {
            $holding = $selected->get($row['isin']);
            $quote = $quotes->get($holding?->quote_id);
            $close = $daily->get($holding?->daily_id);
            if ($quote !== null && $close !== null && $close->trading_date->toDateString() > substr($quote->getRawOriginal('as_of'), 0, 10)) {
                $quote = null;
            }
            $stored = $quote !== null ? [
                'price' => $quote->price, 'source_markers' => MarketDataProvenance::forRecord($quote), 'currency' => $quote->currency,
                'as_of' => CarbonImmutable::parse($quote->getRawOriginal('as_of'), 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'trading_date' => null, 'source' => $quote->source_name ?: $quote->source_key,
                'venue' => $quote->venue, 'mic' => $quote->mic, 'price_type' => $quote->price_type,
            ] : ($close === null ? null : [
                'price' => $close->close, 'source_markers' => MarketDataProvenance::forRecord($close), 'currency' => $close->currency, 'as_of' => null,
                'trading_date' => $close->trading_date->toDateString(), 'source' => $close->source_name ?: $close->source_key,
                'venue' => null, 'mic' => null, 'price_type' => 'daily_close',
            ]);
            if ($stored !== null) {
                $at = $stored['as_of'] ?? $stored['trading_date'];
                $stored['stale'] = CarbonImmutable::parse($at, 'UTC')->lessThan(now()->subDays(4));
            }

            return [...$row, 'stored_quote' => $stored, 'comparison' => $this->compare($row, $stored)];
        }, $result['instruments']);

        return $result;
    }

    /** @param array<string, mixed> $trade @param ?array<string, mixed> $stored @return array<string, mixed> */
    private function compare(array $trade, ?array $stored): array
    {
        if ($stored === null || $trade['price'] === null) {
            return ['value_status' => null, 'comparable' => false,
                'label' => $stored === null ? 'Gespeicherter Kurs fehlt' : 'Börsentrade fehlt', 'reasons' => []];
        }
        if ($stored['currency'] !== $trade['currency']) {
            return ['value_status' => null, 'comparable' => false, 'label' => 'Nicht vergleichbar', 'reasons' => ['Unterschiedliche Währungen']];
        }
        $reasons = [];
        if ($stored['stale'] ?? false) {
            $reasons[] = 'Gespeicherter Kurs veraltet (älter als vier Tage)';
        }
        if ($trade['quote_stale'] ?? false) {
            $reasons[] = 'Quellwert veraltet (älter als vier Tage)';
        }
        if (isset($trade['price_type']) && $trade['price_type'] !== 'trade') {
            $reasons[] = 'Quellkurstyp: '.$trade['price_type'].'; kein verifizierter Einzeltrade';
        }
        if ($stored['as_of'] === null || $stored['as_of'] !== $trade['trade_at']
            || (isset($trade['trade_order_at']) && substr($stored['as_of'], 0, -1).'000Z' !== $trade['trade_order_at'])) {
            $reasons[] = 'Unterschiedliche oder fehlende Datenzeitpunkte';
        }
        if (empty($stored['mic']) || $stored['mic'] !== $trade['trade_venue']) {
            $reasons[] = 'Unterschiedliche oder unbekannte Handelsplatz-MICs';
        }
        if (! in_array($stored['price_type'], ['last', 'trade'], true)) {
            $reasons[] = 'Anderer Kurstyp: '.$stored['price_type'];
        }
        $equal = number_format((float) $stored['price'], 8, '.', '') === number_format((float) $trade['price'], 8, '.', '');

        return ['value_status' => $equal ? 'equal' : 'different', 'comparable' => $reasons === [],
            'label' => ($equal ? 'Wertgleich' : 'Wert abweichend').($reasons === [] ? ' · direkt vergleichbar' : ' · nicht direkt vergleichbar'),
            'reasons' => $reasons];
    }
}
