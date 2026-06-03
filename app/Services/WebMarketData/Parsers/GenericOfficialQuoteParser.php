<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;

class GenericOfficialQuoteParser extends AbstractRegexQuoteParser
{
    public function __construct(private string $parserKey) {}

    public function key(): string
    {
        return $this->parserKey;
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
            $diagnostics->add("{$candidate->sourceName} rejected because ISIN did not match.");

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add("{$candidate->sourceName} rejected because EUR was not visible.");

            return [];
        }

        $last = $this->decimal($this->extract('/(?:Last|Letzter|Kurs|Price|Close)[^0-9]{0,60}(?<value>\d[\d\s.,]*)/iu', $text));
        $date = $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{4}-\d{2}-\d{2})/u', $text);
        $time = $this->extract('/(?<value>\d{1,2}:\d{2}(?::\d{2})?)/u', $text);

        if ($last === null || $date === null || $time === null) {
            $diagnostics->add("{$candidate->sourceName} unavailable because price or timestamp was not parseable.");

            return [];
        }

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $candidate->venue,
                mic: $candidate->mic,
                isin: $instrument->isin,
                wkn: $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                last: $last,
                price: $last,
                priceType: 'last',
                asOf: $this->dateAndTime($date, $time, $this->timezone($candidate)),
                fetchedAt: now(),
                freshnessStatus: $candidate->sourceKey === 'wiener_boerse' ? 'delayed' : 'fresh',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }

    private function timezone(WebSourceCandidate $candidate): string
    {
        return match ($candidate->sourceKey) {
            'wiener_boerse' => 'Europe/Vienna',
            'euronext_live' => 'Europe/Paris',
            default => 'Europe/Berlin',
        };
    }
}
