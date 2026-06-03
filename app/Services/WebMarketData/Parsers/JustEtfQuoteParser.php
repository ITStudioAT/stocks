<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Str;

class JustEtfQuoteParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'justetf';
    }

    public function parse(
        string $content,
        WebSourceCandidate $candidate,
        InstrumentIdentity $instrument,
        ParserDiagnostics $diagnostics,
        ?string $secondaryContent = null,
    ): array {
        if (! $instrument->isEtfLike()) {
            $diagnostics->add('justETF skipped because instrument is not ETF-like.');

            return [];
        }

        $text = $this->cleanText($content);

        if ($instrument->isin !== null && ! str_contains($text, $instrument->isin)) {
            $diagnostics->add('justETF rejected because ISIN did not match.');

            return [];
        }

        if (! $this->containsEuro($text)) {
            $diagnostics->add('justETF rejected because EUR was not visible.');

            return [];
        }

        $isNav = Str::contains(Str::upper($text), ['NAV', 'NET ASSET VALUE']);
        $price = $this->decimal($this->extract('/(?:Kurs|Quote|Price|NAV)[^0-9]{0,60}(?<value>\d[\d\s.,]*)/iu', $text));
        $date = $this->extract('/(?<value>\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/u', $text);
        $time = $this->extract('/(?<value>\d{1,2}:\d{2}(?::\d{2})?)/u', $text);

        if ($price === null) {
            $diagnostics->add('justETF rejected because no price was found.');

            return [];
        }

        return [
            new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $isNav ? 'NAV' : ($candidate->venue ?? 'justETF'),
                mic: $candidate->mic,
                isin: $instrument->isin,
                wkn: $instrument->wkn,
                symbol: $instrument->symbol,
                currency: 'EUR',
                nav: $isNav ? $price : null,
                last: $isNav ? null : $price,
                price: $price,
                priceType: $isNav ? 'nav' : 'last',
                asOf: $this->dateAndTime($date, $time),
                fetchedAt: now(),
                freshnessStatus: $isNav ? 'closed_market' : 'delayed',
                rawTextHash: $this->textHash($text),
            ),
        ];
    }
}
