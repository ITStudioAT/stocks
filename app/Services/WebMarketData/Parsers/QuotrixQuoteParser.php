<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;

class QuotrixQuoteParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'quotrix';
    }

    public function parse(
        string $content,
        WebSourceCandidate $candidate,
        InstrumentIdentity $instrument,
        ParserDiagnostics $diagnostics,
        ?string $secondaryContent = null,
    ): array {
        $text = $this->cleanText($content);
        $isin = $this->upperIdentifier($this->extract('/\b(?<value>[A-Z]{2}[A-Z0-9]{10})\b/u', $text));

        if ($instrument->isin !== null && $isin !== null && $isin !== $instrument->isin) {
            $diagnostics->add('Quotrix rejected because ISIN did not match.');

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add('Quotrix rejected because EUR was not visible.');

            return [];
        }

        $bid = $this->decimal($this->extract('/(?:Geld|Bid)[^0-9]{0,50}(?<value>\d[\d\s.,]*)/iu', $text));
        $ask = $this->decimal($this->extract('/(?:Brief|Ask)[^0-9]{0,50}(?<value>\d[\d\s.,]*)/iu', $text));
        $last = $this->decimal($this->extract('/(?:Letzter|Stand|Last)[^0-9]{0,50}(?<value>\d[\d\s.,]*)/iu', $text));
        $time = $this->extract('/(?:Stand|Update)[^0-9]{0,50}(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\s+\d{1,2}:\d{2}(?::\d{2})?)/iu', $text);
        [$price, $priceType] = $this->priceAndType($bid, $ask, $last);

        if ($price === null) {
            $diagnostics->add('Quotrix rejected because no usable price was found.');

            return [];
        }

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $candidate->venue ?? 'Quotrix',
                mic: $candidate->mic,
                isin: $isin ?? $instrument->isin,
                wkn: $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                bid: $bid,
                ask: $ask,
                last: $last,
                price: $price,
                priceType: $priceType,
                asOf: $this->dateTime($time),
                fetchedAt: now(),
                freshnessStatus: 'fresh',
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
}
