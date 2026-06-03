<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Carbon;

class TradegateQuoteParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'tradegate';
    }

    public function parse(
        string $content,
        WebSourceCandidate $candidate,
        InstrumentIdentity $instrument,
        ParserDiagnostics $diagnostics,
        ?string $secondaryContent = null,
    ): array {
        $text = $this->cleanText($content.' '.$secondaryContent);
        $isin = $this->upperIdentifier($this->extract('/\b(?<value>[A-Z]{2}[A-Z0-9]{10})\b/u', $text));
        $wkn = $this->upperIdentifier($this->extract('/\bWKN[:\s]*(?<value>[A-Z0-9]{6})\b/iu', $text));

        if ($instrument->isin !== null && $isin !== null && $isin !== $instrument->isin) {
            $diagnostics->add('Tradegate rejected because ISIN did not match.');

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add('Tradegate rejected because EUR currency was not visible.');

            return [];
        }

        $bid = $this->decimal($this->extract('/(?:Geld|Bid)[^0-9]{0,40}(?<value>\d[\d\s.,]*)/iu', $text));
        $ask = $this->decimal($this->extract('/(?:Brief|Ask)[^0-9]{0,40}(?<value>\d[\d\s.,]*)/iu', $text));
        $last = $this->decimal($this->extract('/(?:Letzter|Last|Kurs)[^0-9]{0,40}(?<value>\d[\d\s.,]*)/iu', $text));
        $asOf = $this->extractAsOf($text);

        if ($bid === null && $ask === null && $last === null) {
            $diagnostics->add('Tradegate rejected because no bid, ask, or last price was found.');

            return [];
        }

        [$price, $priceType] = $this->priceAndType($bid, $ask, $last);

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $candidate->venue ?? 'Tradegate',
                mic: $candidate->mic,
                isin: $isin ?? $instrument->isin,
                wkn: $wkn ?? $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                bid: $bid,
                ask: $ask,
                last: $last,
                price: $price,
                priceType: $priceType,
                asOf: $asOf,
                fetchedAt: now(),
                freshnessStatus: $asOf ? 'fresh' : 'unavailable',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function priceAndType(?string $bid, ?string $ask, ?string $last): array
    {
        if ($bid !== null && $ask !== null) {
            return [number_format(((float) $bid + (float) $ask) / 2, 8, '.', ''), 'indicative_mid'];
        }

        return [$last, $last === null ? 'unavailable' : 'last'];
    }

    private function extractAsOf(string $text): ?Carbon
    {
        $date = $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/u', $text);
        $time = $this->extract('/(?<value>\d{1,2}:\d{2}(?::\d{2})?)/u', $text);

        return $this->dateAndTime($date, $time);
    }
}
