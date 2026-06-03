<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Carbon;

class BxSwissParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'bx_swiss';
    }

    public function parse(
        string $content,
        WebSourceCandidate $candidate,
        InstrumentIdentity $instrument,
        ParserDiagnostics $diagnostics,
        ?string $secondaryContent = null,
    ): array {
        $text = $this->cleanText($content);

        if ($instrument->isin !== null && ! str_contains($text, $instrument->isin)) {
            $diagnostics->add('BX Swiss rejected because ISIN did not match.');

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add('BX Swiss rejected because EUR was not visible.');

            return [];
        }

        $bid = $this->decimal($this->extract('/\bBid\s+EUR\s+(?<value>\d[\d\s.,\'\x{2019}]*)/iu', $text));
        $ask = $this->decimal($this->extract('/\bAsk\s+EUR\s+(?<value>\d[\d\s.,\'\x{2019}]*)/iu', $text));
        $time = $this->extract('/\bLast update\s+(?<value>\d{1,2}:\d{2}(?::\d{2})?)\s*(?:CET|CEST)?/iu', $text);
        $asOf = $this->asOf($time);

        if ($bid === null || $ask === null) {
            $diagnostics->add('BX Swiss rejected because bid/ask was not found.');

            return [];
        }

        if ($asOf === null) {
            $diagnostics->add('BX Swiss rejected because last update time was not found.');

            return [];
        }

        $price = number_format(((float) $bid + (float) $ask) / 2, 8, '.', '');

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: 'BX Swiss',
                mic: $candidate->mic,
                isin: $instrument->isin,
                wkn: $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                bid: $bid,
                ask: $ask,
                price: $price,
                priceType: 'indicative_mid',
                asOf: $asOf,
                fetchedAt: now(),
                freshnessStatus: 'delayed',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }

    private function asOf(?string $time): ?Carbon
    {
        if ($time === null) {
            return null;
        }

        $asOf = $this->dateTime(now('Europe/Zurich')->toDateString()." {$time}", 'Europe/Zurich');

        if ($asOf instanceof Carbon && $asOf->gt(now('Europe/Zurich')->addMinutes(2))) {
            return $asOf->subDay();
        }

        return $asOf;
    }
}
