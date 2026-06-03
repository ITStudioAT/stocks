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

        $venueQuotes = $this->venueQuotes($text, $candidate, $instrument);

        if ($venueQuotes !== []) {
            return $venueQuotes;
        }

        $last = $this->decimal($this->extract('/(?:Letzter|Aktueller Kurs|Aktuell|Kurs)[^0-9]{0,60}(?<value>\d[\d\s.,]*)/iu', $text))
            ?? $this->decimal($this->extract('/(?<value>\d[\d\s.,]*)\s*EUR\s+\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\s+\d{1,2}:\d{2}(?::\d{2})?/iu', $text));
        $date = $this->extract('/(?:Datum|Date)[^0-9]{0,30}(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/iu', $text)
            ?? $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})\s+\d{1,2}:\d{2}(?::\d{2})?/u', $text)
            ?? $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/u', $text);
        $time = $this->extract('/(?:Kurszeit|Zeit|Time)[^0-9]{0,30}(?<value>\d{1,2}:\d{2}(?::\d{2})?)/iu', $text)
            ?? $this->extract('/\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\s+(?<value>\d{1,2}:\d{2}(?::\d{2})?)/u', $text)
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
                asOf: $this->dateAndTime($date, $time, $this->timezone($text)),
                fetchedAt: now(),
                freshnessStatus: 'delayed',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }

    /**
     * @return array<int, ParsedQuote>
     */
    private function venueQuotes(string $text, WebSourceCandidate $candidate, InstrumentIdentity $instrument): array
    {
        preg_match_all(
            '/(?<price>\d[\d\s.,]*)\s*EUR\b.{0,300}?(?:Datum|Date)\s*(?<date>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})\s+(?<time>\d{1,2}:\d{2}(?::\d{2})?).{0,220}?(?:Börse|Boerse)\s*(?<venue>[A-ZÄÖÜ][\pL\s&.\'-]{1,40})/iu',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->map(function (array $match) use ($candidate, $instrument, $text): ?ParsedQuote {
                $last = $this->decimal($match['price'] ?? null);
                $venue = $this->cleanVenue((string) ($match['venue'] ?? ''));

                if ($last === null) {
                    return null;
                }

                return new ParsedQuote(
                    sourceKey: $candidate->sourceKey,
                    sourceName: $candidate->sourceName,
                    sourceUrl: $candidate->url,
                    sourceQuality: $candidate->quality,
                    venue: $venue,
                    mic: $candidate->mic,
                    isin: $instrument->isin,
                    wkn: $instrument->wkn,
                    symbol: $instrument->symbol,
                    currency: 'EUR',
                    last: $last,
                    price: $last,
                    priceType: 'last',
                    asOf: $this->dateAndTime($match['date'] ?? null, $match['time'] ?? null, $this->timezone($venue)),
                    fetchedAt: now(),
                    freshnessStatus: 'delayed',
                    rawTextHash: $this->textHash($text),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function cleanVenue(string $venue): ?string
    {
        $venue = trim(preg_replace('/\s+/u', ' ', $venue) ?? $venue);

        return $venue === '' ? null : $venue;
    }

    private function venue(string $text, WebSourceCandidate $candidate): ?string
    {
        if (str_contains($text, 'BX Swiss')) {
            return 'BX Swiss';
        }

        foreach (['Wien', 'Vienna', 'Xetra', 'Tradegate', 'gettex', 'Lang & Schwarz', 'L&S Exchange', 'Quotrix', 'Stuttgart', 'Düsseldorf', 'Hamburg', 'München', 'Frankfurt'] as $venue) {
            if (str_contains($text, $venue)) {
                return $venue;
            }
        }

        return $candidate->venue;
    }

    private function timezone(?string $text): string
    {
        $text = (string) $text;

        if (str_contains($text, 'Wien') || str_contains($text, 'Vienna')) {
            return 'Europe/Vienna';
        }

        return 'Europe/Berlin';
    }
}
