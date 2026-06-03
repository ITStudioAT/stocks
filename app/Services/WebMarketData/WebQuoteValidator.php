<?php

namespace App\Services\WebMarketData;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use Illuminate\Support\Str;

class WebQuoteValidator
{
    public function __construct(
        private MarketHours $marketHours,
    ) {}

    public function validate(ParsedQuote $quote, InstrumentIdentity $instrument): ValidatedQuote
    {
        $errors = [];
        $status = 'valid';
        $freshnessStatus = $this->freshnessStatus($quote);

        if ($quote->price === null || (float) $quote->price <= 0) {
            $errors[] = 'Price is missing or not greater than zero.';
        }

        if ((bool) config('market-data.strict_currency', true) && Str::upper((string) $quote->currency) !== (string) config('market-data.default_currency', 'EUR')) {
            $errors[] = 'Currency is not EUR.';
        }

        if ($instrument->isin !== null && $quote->isin !== null && Str::upper($quote->isin) !== $instrument->isin) {
            $errors[] = 'ISIN does not match holding.';
        }

        if ($instrument->wkn !== null && $quote->wkn !== null && Str::upper($quote->wkn) !== $instrument->wkn) {
            $errors[] = 'WKN does not match holding.';
        }

        if ($quote->bid !== null && $quote->ask !== null && (float) $quote->bid > (float) $quote->ask) {
            $errors[] = 'Bid is greater than ask.';
        }

        if ($quote->asOf !== null && $quote->asOf->gt(now()->addMinutes(2))) {
            $errors[] = 'Quote timestamp is in the future.';
        }

        if ($quote->priceType === 'nav') {
            $freshnessStatus = 'closed_market';
            $status = 'suspicious';
            $errors[] = 'NAV is stored but not treated as a live exchange price.';
        }

        [$spreadAbs, $spreadPct] = $this->spread($quote);

        if ($spreadPct !== null && (float) $spreadPct > (float) config('market-data.max_spread_pct_warning', 2.0)) {
            $status = 'suspicious';
            $errors[] = 'Spread exceeds warning threshold.';
        }

        if ($instrument->lastPrice !== null && $quote->price !== null) {
            $jumpPct = $this->priceJumpPct((float) $instrument->lastPrice, (float) $quote->price);

            if ($jumpPct > (float) config('market-data.max_price_jump_pct_warning', 25.0)) {
                $status = 'suspicious';
                $errors[] = 'Price jump exceeds warning threshold and requires cross-check.';
            }
        }

        if ($quote->asOf === null) {
            $status = 'invalid';
            $freshnessStatus = 'invalid';
            $errors[] = 'Quote timestamp is missing.';
        }

        if ($errors !== [] && $status === 'valid') {
            $status = 'invalid';
        }

        return new ValidatedQuote(
            quote: $quote,
            validationStatus: $status,
            freshnessStatus: $freshnessStatus,
            validationErrors: $errors,
            spreadAbs: $spreadAbs,
            spreadPct: $spreadPct,
        );
    }

    private function freshnessStatus(ParsedQuote $quote): string
    {
        if ($quote->asOf === null) {
            return 'unavailable';
        }

        $ageSeconds = $quote->asOf->diffInSeconds(now(), false);

        if ($ageSeconds < 0) {
            return 'invalid';
        }

        if ($ageSeconds <= (int) config('market-data.realtime_window_seconds', 300)) {
            return $quote->freshnessStatus === 'delayed' ? 'delayed' : 'realtime';
        }

        if ($ageSeconds <= (int) config('market-data.delayed_window_seconds', 1800)) {
            return $quote->freshnessStatus === 'delayed' ? 'delayed' : 'fresh';
        }

        return $this->marketClosed($quote) ? 'closed_market' : 'stale';
    }

    private function marketClosed(ParsedQuote $quote): bool
    {
        return ! $this->marketHours->isOpen($quote);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function spread(ParsedQuote $quote): array
    {
        if ($quote->bid === null || $quote->ask === null || (float) $quote->bid <= 0 || (float) $quote->ask <= 0) {
            return [null, null];
        }

        $spreadAbs = (float) $quote->ask - (float) $quote->bid;
        $mid = ((float) $quote->ask + (float) $quote->bid) / 2;

        return [
            number_format($spreadAbs, 8, '.', ''),
            number_format(($spreadAbs / $mid) * 100, 6, '.', ''),
        ];
    }

    private function priceJumpPct(float $oldPrice, float $newPrice): float
    {
        if ($oldPrice <= 0) {
            return 0.0;
        }

        return abs(($newPrice - $oldPrice) / $oldPrice) * 100;
    }
}
