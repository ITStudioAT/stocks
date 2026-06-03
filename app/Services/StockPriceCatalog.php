<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class StockPriceCatalog
{
    public function store(StockHolding $holding, ValidatedQuote $validatedQuote, ?string $tradingTimes = null): StockPrice
    {
        $quote = $validatedQuote->quote;
        $instrumentKey = $this->instrumentKeyForQuote($holding, $quote);
        $quoteHash = $this->quoteHash($instrumentKey, $quote);

        return StockPrice::query()->updateOrCreate(
            ['quote_hash' => $quoteHash],
            [
                'instrument_key' => $instrumentKey,
                'source_key' => $quote->sourceKey,
                'source_name' => $quote->sourceName,
                'source_url' => $quote->sourceUrl,
                'source_quality' => $quote->sourceQuality,
                'venue' => $quote->venue,
                'mic' => $quote->mic,
                'isin' => $this->upperIdentifier($quote->isin ?? $holding->isin),
                'wkn' => $this->upperIdentifier($quote->wkn ?? $holding->wkn),
                'symbol' => $this->upperIdentifier($quote->symbol ?? $holding->symbol),
                'currency' => $this->upperIdentifier($quote->currency),
                'bid' => $quote->bid,
                'ask' => $quote->ask,
                'last' => $quote->last,
                'close' => $quote->close,
                'nav' => $quote->nav,
                'price' => $quote->price,
                'price_type' => $quote->priceType,
                'spread_abs' => $validatedQuote->spreadAbs,
                'spread_pct' => $validatedQuote->spreadPct,
                'as_of' => $quote->asOf?->copy()->utc(),
                'fetched_at' => $quote->fetchedAt?->copy()->utc(),
                'freshness_status' => $validatedQuote->freshnessStatus,
                'validation_status' => $validatedQuote->validationStatus,
                'validation_errors' => $validatedQuote->validationErrors,
                'raw_text_hash' => $quote->rawTextHash,
                'raw_payload' => (bool) config('market-data.store_raw_payloads') ? $quote->rawPayload : null,
                'trading_times' => $tradingTimes,
            ],
        );
    }

    /**
     * @return Builder<StockPrice>
     */
    public function pricesForHolding(StockHolding $holding): Builder
    {
        return StockPrice::query()
            ->where('instrument_key', $this->instrumentKeyForHolding($holding));
    }

    public function instrumentKeyForHolding(StockHolding $holding): string
    {
        return $this->instrumentKey(
            isin: $holding->isin,
            wkn: $holding->wkn,
            symbol: $holding->symbol,
            mic: $holding->mic_code,
            venue: $holding->exchange,
            fallback: "holding:{$holding->id}",
        );
    }

    private function instrumentKeyForQuote(StockHolding $holding, ParsedQuote $quote): string
    {
        return $this->instrumentKey(
            isin: $quote->isin ?? $holding->isin,
            wkn: $quote->wkn ?? $holding->wkn,
            symbol: $quote->symbol ?? $holding->symbol,
            mic: $quote->mic ?? $holding->mic_code,
            venue: $quote->venue ?? $holding->exchange,
            fallback: "holding:{$holding->id}",
        );
    }

    private function instrumentKey(
        ?string $isin,
        ?string $wkn,
        ?string $symbol,
        ?string $mic,
        ?string $venue,
        string $fallback,
    ): string {
        if ($this->upperIdentifier($isin) !== null) {
            return 'isin:'.$this->upperIdentifier($isin);
        }

        if ($this->upperIdentifier($wkn) !== null) {
            return 'wkn:'.$this->upperIdentifier($wkn);
        }

        if ($this->upperIdentifier($symbol) !== null) {
            return collect([
                'symbol:'.$this->upperIdentifier($symbol),
                $this->upperIdentifier($mic) ? 'mic:'.$this->upperIdentifier($mic) : null,
                $this->normalizedText($venue) ? 'venue:'.$this->normalizedText($venue) : null,
            ])->filter()->implode('|');
        }

        return $fallback;
    }

    private function quoteHash(string $instrumentKey, ParsedQuote $quote): string
    {
        return hash('sha256', json_encode([
            'instrument_key' => $instrumentKey,
            'source_key' => $this->normalizedText($quote->sourceKey),
            'source_url' => $this->normalizedText($quote->sourceUrl),
            'venue' => $this->normalizedText($quote->venue),
            'mic' => $this->upperIdentifier($quote->mic),
            'currency' => $this->upperIdentifier($quote->currency),
            'bid' => $this->normalizedDecimal($quote->bid),
            'ask' => $this->normalizedDecimal($quote->ask),
            'last' => $this->normalizedDecimal($quote->last),
            'close' => $this->normalizedDecimal($quote->close),
            'nav' => $this->normalizedDecimal($quote->nav),
            'price' => $this->normalizedDecimal($quote->price),
            'price_type' => $this->normalizedText($quote->priceType),
            'as_of' => $quote->asOf?->copy()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function upperIdentifier(?string $value): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : Str::upper($value);
    }

    private function normalizedText(?string $value): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : Str::lower($value);
    }

    private function nullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizedDecimal(?string $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }
}
