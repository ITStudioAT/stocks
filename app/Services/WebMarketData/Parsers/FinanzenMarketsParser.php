<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;

class FinanzenMarketsParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'finanzen_markets';
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
            $diagnostics->add('finanzen rejected because ISIN did not match.');

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add('finanzen rejected because EUR was not visible.');

            return [];
        }

        $last = $this->decimal($this->extract('/(?:Letzter|Kurs)[^0-9]{0,60}(?<value>\d[\d\s.,]*)/iu', $text));
        $date = $this->extract('/(?:Datum|Date)[^0-9]{0,30}(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/iu', $text)
            ?? $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/u', $text);
        $time = $this->extract('/(?:Kurszeit|Zeit|Time)[^0-9]{0,30}(?<value>\d{1,2}:\d{2}(?::\d{2})?)/iu', $text)
            ?? $this->extract('/(?<value>\d{1,2}:\d{2}(?::\d{2})?)/u', $text);

        if ($last === null) {
            $diagnostics->add('finanzen rejected because no last price was found.');

            return [];
        }

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $this->venue($text, $candidate),
                mic: $candidate->mic,
                isin: $instrument->isin,
                wkn: $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                last: $last,
                price: $last,
                priceType: 'last',
                asOf: $this->dateAndTime($date, $time),
                fetchedAt: now(),
                freshnessStatus: 'delayed',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }

    private function venue(string $text, WebSourceCandidate $candidate): ?string
    {
        foreach (['Tradegate', 'gettex', 'Lang & Schwarz', 'L&S Exchange', 'Quotrix', 'Stuttgart', 'Düsseldorf', 'Hamburg', 'München', 'Frankfurt', 'Wien'] as $venue) {
            if (str_contains($text, $venue)) {
                return $venue;
            }
        }

        return $candidate->venue;
    }
}
