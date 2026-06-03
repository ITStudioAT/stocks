<?php

namespace App\Services\WebMarketData;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebQuoteSelector
{
    /**
     * @param  array<int, ValidatedQuote>  $quotes
     * @param  array<int, WebSourceCandidate>  $attemptedSources
     */
    public function select(array $quotes, InstrumentIdentity $instrument, array $attemptedSources, bool $applyCrossCheck = true): QuoteSelectionResult
    {
        $selectable = collect($quotes)->filter(fn (ValidatedQuote $quote): bool => $quote->isSelectable());

        $usedQuotes = $this->calculationQuotes($selectable, $instrument);
        $selected = $usedQuotes->isNotEmpty()
            ? $this->calculatedQuote($usedQuotes, $instrument)
            : null;

        if (! $selected) {
            return new QuoteSelectionResult(null, $quotes, $attemptedSources, status: 'unavailable');
        }

        $crossCheck = $usedQuotes->count() > 1
            ? $usedQuotes->first()
            : $selectable
                ->reject(fn (ValidatedQuote $quote): bool => $usedQuotes->containsStrict($quote))
                ->pipe(fn (Collection $quotes): Collection => $this->rankedQuotes($quotes, $instrument))
                ->first();

        $status = $selected->validationStatus === 'suspicious' ? 'suspicious' : $selected->freshnessStatus;

        if ($this->quotesHaveWideVariance($usedQuotes)) {
            if ($applyCrossCheck) {
                $selected->validationStatus = 'suspicious';
                $selected->validationErrors[] = 'Used quotes diverge beyond the configured tolerance.';
            }

            $status = 'suspicious';
        }

        return new QuoteSelectionResult(
            selectedQuote: $selected,
            quotes: $quotes,
            attemptedSources: $attemptedSources,
            status: $status,
            crossCheckQuote: $crossCheck,
            usedQuotes: $usedQuotes->values()->all(),
            arithmeticMean: $this->arithmeticMean($usedQuotes),
            median: $this->median($usedQuotes),
            confidence: $this->confidence($usedQuotes, $status),
            reason: $this->reason($usedQuotes, $status),
        );
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $selectable
     * @return Collection<int, ValidatedQuote>
     */
    private function calculationQuotes(Collection $selectable, InstrumentIdentity $instrument): Collection
    {
        $validNearLive = $selectable
            ->filter(fn (ValidatedQuote $quote): bool => $quote->validationStatus === 'valid')
            ->filter(fn (ValidatedQuote $quote): bool => in_array($quote->freshnessStatus, ['realtime', 'fresh', 'delayed'], true));

        if ($validNearLive->isNotEmpty()) {
            return $this->withoutOutliers($validNearLive, $instrument);
        }

        $validClosedMarket = $selectable
            ->filter(fn (ValidatedQuote $quote): bool => $quote->validationStatus === 'valid')
            ->filter(fn (ValidatedQuote $quote): bool => $quote->freshnessStatus === 'closed_market');

        if ($validClosedMarket->isNotEmpty()) {
            return $this->withoutOutliers($validClosedMarket, $instrument);
        }

        return $this->withoutOutliers(
            $selectable->filter(fn (ValidatedQuote $quote): bool => $quote->validationStatus === 'suspicious'),
            $instrument,
        );
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     * @return Collection<int, ValidatedQuote>
     */
    private function withoutOutliers(Collection $quotes, InstrumentIdentity $instrument): Collection
    {
        $rankedQuotes = $this->rankedQuotes($quotes, $instrument);

        if ($rankedQuotes->count() < 3) {
            return $rankedQuotes;
        }

        $median = (float) $this->median($rankedQuotes);
        $tolerance = (float) config('market-data.outlier_tolerance_pct', 3.0);

        if ($median <= 0) {
            return $rankedQuotes;
        }

        $filtered = $rankedQuotes->filter(function (ValidatedQuote $quote) use ($median, $tolerance): bool {
            $price = (float) $quote->quote->price;

            return abs(($price - $median) / $median) * 100 <= $tolerance;
        });

        if ($filtered->isEmpty()) {
            return $rankedQuotes;
        }

        $filteredQuoteIds = $filtered->mapWithKeys(fn (ValidatedQuote $quote): array => [spl_object_id($quote) => true]);

        $rankedQuotes
            ->reject(fn (ValidatedQuote $quote): bool => $filteredQuoteIds->has(spl_object_id($quote)))
            ->each(function (ValidatedQuote $quote): void {
                $quote->validationStatus = 'suspicious';
                $quote->validationErrors[] = 'Excluded from median calculation as a price outlier.';
            });

        return $filtered->values();
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function calculatedQuote(Collection $quotes, InstrumentIdentity $instrument): ValidatedQuote
    {
        $latestQuote = $quotes
            ->sortByDesc(fn (ValidatedQuote $quote): int => $quote->quote->asOf?->getTimestamp() ?? 0)
            ->first();
        $median = $this->median($quotes);
        $status = $this->calculatedFreshnessStatus($quotes);

        $quote = new ParsedQuote(
            sourceKey: 'calculated_median',
            sourceName: sprintf('%d quotes', $quotes->count()),
            sourceUrl: $latestQuote->quote->sourceUrl,
            sourceQuality: 'calculated',
            venue: $latestQuote->quote->venue,
            mic: $latestQuote->quote->mic,
            isin: $instrument->isin,
            wkn: $instrument->wkn,
            symbol: $instrument->symbol,
            currency: $latestQuote->quote->currency,
            price: $median,
            priceType: 'calculated_median',
            asOf: $latestQuote->quote->asOf,
            fetchedAt: now(),
            freshnessStatus: $status,
        );

        return new ValidatedQuote(
            quote: $quote,
            validationStatus: 'valid',
            freshnessStatus: $status,
            validationErrors: [
                "Calculation method: median of {$quotes->count()} valid quote(s).",
                "Arithmetic mean: {$this->arithmeticMean($quotes)}.",
                "Median: {$median}.",
                "Confidence: {$this->confidence($quotes, $status)}.",
            ],
        );
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function calculatedFreshnessStatus(Collection $quotes): string
    {
        foreach (['realtime', 'fresh', 'delayed', 'closed_market', 'suspicious'] as $status) {
            if ($quotes->contains(fn (ValidatedQuote $quote): bool => $quote->freshnessStatus === $status)) {
                return $status;
            }
        }

        return 'unavailable';
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function arithmeticMean(Collection $quotes): string
    {
        return number_format($quotes->avg(fn (ValidatedQuote $quote): float => (float) $quote->quote->price), 6, '.', '');
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function median(Collection $quotes): string
    {
        $prices = $quotes
            ->map(fn (ValidatedQuote $quote): float => (float) $quote->quote->price)
            ->sort()
            ->values();
        $count = $prices->count();
        $middle = intdiv($count, 2);

        if ($count === 0) {
            return '0.000000';
        }

        if ($count % 2 === 1) {
            return number_format($prices[$middle], 6, '.', '');
        }

        return number_format(($prices[$middle - 1] + $prices[$middle]) / 2, 6, '.', '');
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function confidence(Collection $quotes, string $status): string
    {
        if ($quotes->count() >= 3 && in_array($status, ['realtime', 'fresh', 'delayed'], true)) {
            return 'high';
        }

        if ($quotes->count() >= 2 && in_array($status, ['realtime', 'fresh', 'delayed'], true)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function reason(Collection $quotes, string $status): string
    {
        return sprintf(
            'Calculated %s median from %d valid quote(s); latest used timestamp is %s.',
            $status,
            $quotes->count(),
            $quotes->max(fn (ValidatedQuote $quote): ?string => $quote->quote->asOf?->toDateTimeString()) ?? 'unknown',
        );
    }

    /**
     * @return array<int, int|float>
     */
    private function ranking(ValidatedQuote $quote, InstrumentIdentity $instrument): array
    {
        return [
            $quote->validationStatus === 'valid' ? 0 : 1,
            $this->freshnessRank($quote->freshnessStatus),
            $this->recencyRank($quote),
            $this->preferredVenueRank($quote, $instrument),
            $this->qualityRank($quote->quote->sourceQuality),
            $this->priceTypeRank($quote->quote->priceType),
            $quote->spreadPct === null ? 999.0 : (float) $quote->spreadPct,
            (int) config("market-data.sources.{$quote->quote->sourceKey}.priority", 999),
        ];
    }

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     * @return Collection<int, ValidatedQuote>
     */
    private function rankedQuotes(Collection $quotes, InstrumentIdentity $instrument): Collection
    {
        return $quotes->sort(function (ValidatedQuote $left, ValidatedQuote $right) use ($instrument): int {
            $leftRanking = $this->ranking($left, $instrument);
            $rightRanking = $this->ranking($right, $instrument);

            foreach ($leftRanking as $index => $leftRank) {
                $rightRank = $rightRanking[$index];

                if ($leftRank === $rightRank) {
                    continue;
                }

                return $leftRank <=> $rightRank;
            }

            return 0;
        });
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

        if ($this->matchesInstrumentExchange($quote, $instrument)) {
            return 1;
        }

        return 2;
    }

    private function recencyRank(ValidatedQuote $quote): int
    {
        return -($quote->quote->asOf?->getTimestamp() ?? 0);
    }

    private function matchesInstrumentExchange(ValidatedQuote $quote, InstrumentIdentity $instrument): bool
    {
        if (! $instrument->exchange || ! $quote->quote->venue) {
            return false;
        }

        return Str::contains(Str::lower($quote->quote->venue), Str::lower($instrument->exchange));
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

    /**
     * @param  Collection<int, ValidatedQuote>  $quotes
     */
    private function quotesHaveWideVariance(Collection $quotes): bool
    {
        if ($quotes->count() < 2) {
            return false;
        }

        $median = (float) $this->median($quotes);

        if ($median <= 0) {
            return false;
        }

        return $quotes->contains(function (ValidatedQuote $quote) use ($median): bool {
            $price = (float) $quote->quote->price;

            return abs(($price - $median) / $median) * 100 > (float) config('market-data.cross_check_tolerance_pct', 1.5);
        });
    }
}
