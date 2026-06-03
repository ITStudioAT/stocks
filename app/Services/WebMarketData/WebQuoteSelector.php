<?php

namespace App\Services\WebMarketData;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use App\Services\WebMarketData\DTO\WebSourceCandidate;

class WebQuoteSelector
{
    /**
     * @param  array<int, ValidatedQuote>  $quotes
     * @param  array<int, WebSourceCandidate>  $attemptedSources
     */
    public function select(array $quotes, InstrumentIdentity $instrument, array $attemptedSources): QuoteSelectionResult
    {
        $selectable = collect($quotes)->filter(fn (ValidatedQuote $quote): bool => $quote->isSelectable());
        $selected = $selectable
            ->sortBy(fn (ValidatedQuote $quote): array => $this->ranking($quote, $instrument))
            ->first();

        if (! $selected) {
            return new QuoteSelectionResult(null, $quotes, $attemptedSources, status: 'unavailable');
        }

        $crossCheck = $selectable
            ->filter(fn (ValidatedQuote $quote): bool => $quote !== $selected && $quote->quote->sourceKey !== $selected->quote->sourceKey)
            ->sortBy(fn (ValidatedQuote $quote): array => $this->ranking($quote, $instrument))
            ->first();

        $status = $selected->validationStatus === 'suspicious' ? 'suspicious' : $selected->freshnessStatus;

        if ($crossCheck && $this->quotesDiverge($selected, $crossCheck)) {
            $selected->validationStatus = 'suspicious';
            $selected->validationErrors[] = 'Cross-check quote diverges from selected quote.';
            $status = 'suspicious';
        }

        return new QuoteSelectionResult($selected, $quotes, $attemptedSources, status: $status, crossCheckQuote: $crossCheck);
    }

    /**
     * @return array<int, int|float>
     */
    private function ranking(ValidatedQuote $quote, InstrumentIdentity $instrument): array
    {
        return [
            $quote->validationStatus === 'valid' ? 0 : 1,
            $this->qualityRank($quote->quote->sourceQuality),
            $this->freshnessRank($quote->freshnessStatus),
            $this->preferredVenueRank($quote, $instrument),
            $this->priceTypeRank($quote->quote->priceType),
            $quote->spreadPct === null ? 999.0 : (float) $quote->spreadPct,
            (int) config("market-data.sources.{$quote->quote->sourceKey}.priority", 999),
        ];
    }

    private function qualityRank(string $quality): int
    {
        return match ($quality) {
            'exchange_official' => 0,
            'official_venue' => 1,
            'multi_venue_portal' => 2,
            'etf_portal' => 3,
            'finance_portal' => 4,
            default => 5,
        };
    }

    private function freshnessRank(string $freshnessStatus): int
    {
        return match ($freshnessStatus) {
            'realtime' => 0,
            'fresh' => 1,
            'delayed' => 2,
            'closed_market' => 3,
            'stale' => 4,
            default => 5,
        };
    }

    private function preferredVenueRank(ValidatedQuote $quote, InstrumentIdentity $instrument): int
    {
        if ($instrument->preferredMic && $quote->quote->mic === $instrument->preferredMic) {
            return 0;
        }

        if ($instrument->preferredVenue && $quote->quote->venue && strcasecmp($quote->quote->venue, $instrument->preferredVenue) === 0) {
            return 0;
        }

        if ($instrument->preferredSourceKey && $quote->quote->sourceKey === $instrument->preferredSourceKey) {
            return 1;
        }

        return 2;
    }

    private function priceTypeRank(string $priceType): int
    {
        return match ($priceType) {
            'indicative_mid' => 0,
            'last' => 1,
            'close' => 2,
            'nav' => 3,
            default => 4,
        };
    }

    private function quotesDiverge(ValidatedQuote $selected, ValidatedQuote $crossCheck): bool
    {
        if ($selected->quote->price === null || $crossCheck->quote->price === null) {
            return false;
        }

        $selectedPrice = (float) $selected->quote->price;
        $crossCheckPrice = (float) $crossCheck->quote->price;

        if ($selectedPrice <= 0) {
            return false;
        }

        return abs(($selectedPrice - $crossCheckPrice) / $selectedPrice) * 100 > (float) config('market-data.cross_check_tolerance_pct', 1.5);
    }
}
