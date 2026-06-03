<?php

namespace App\Services\WebMarketData\Parsers;

use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class OnvistaMarketsParser extends AbstractRegexQuoteParser
{
    public function key(): string
    {
        return 'onvista_markets';
    }

    public function parse(
        string $content,
        WebSourceCandidate $candidate,
        InstrumentIdentity $instrument,
        ParserDiagnostics $diagnostics,
        ?string $secondaryContent = null,
    ): array {
        if (preg_match('~<script id="__NEXT_DATA__" type="application/json">(?<json>.*?)</script>~s', $content, $matches)) {
            return $this->parseNextData(html_entity_decode($matches['json'], ENT_QUOTES | ENT_HTML5), $candidate, $instrument, $diagnostics);
        }

        $diagnostics->add('Onvista page did not expose structured quote data.');

        return [];
    }

    /**
     * @return array<int, ParsedQuote>
     */
    private function parseNextData(string $json, WebSourceCandidate $candidate, InstrumentIdentity $instrument, ParserDiagnostics $diagnostics): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            $diagnostics->add('Onvista JSON could not be decoded.');

            return [];
        }

        $snapshot = Arr::get($data, 'props.pageProps.data.snapshot');

        if (! is_array($snapshot)) {
            $diagnostics->add('Onvista snapshot was missing.');

            return [];
        }

        $isin = $this->upperIdentifier(Arr::get($snapshot, 'instrument.isin'));
        $wkn = $this->upperIdentifier(Arr::get($snapshot, 'instrument.wkn'));

        if ($instrument->isin !== null && $isin !== null && $isin !== $instrument->isin) {
            $diagnostics->add('Onvista rejected because ISIN did not match.');

            return [];
        }

        $quotes = Arr::get($snapshot, 'quoteList.list');
        $quotes = is_array($quotes) && $quotes !== [] ? $quotes : array_filter([Arr::get($snapshot, 'quote')]);
        $parsedQuotes = [];

        foreach ($quotes as $quote) {
            if (! is_array($quote) || $this->upperIdentifier(Arr::get($quote, 'isoCurrency')) !== 'EUR') {
                continue;
            }

            $venue = (string) (Arr::get($quote, 'market.nameExchange') ?? Arr::get($quote, 'market.name') ?? 'onvista');
            $last = $this->decimal(Arr::get($quote, 'last'));
            $bid = $this->decimal(Arr::get($quote, 'bid'));
            $ask = $this->decimal(Arr::get($quote, 'ask'));
            [$price, $priceType] = $this->priceAndType($bid, $ask, $last);

            if ($price === null) {
                continue;
            }

            $parsedQuotes[] = new ParsedQuote(
                sourceKey: $candidate->sourceKey,
                sourceName: $candidate->sourceName,
                sourceUrl: $candidate->url,
                sourceQuality: $candidate->quality,
                venue: $venue,
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
                asOf: $this->onvistaDateTime(Arr::get($quote, 'datetimeLast')),
                fetchedAt: now(),
                freshnessStatus: 'delayed',
                rawTextHash: $this->textHash($json),
            );
        }

        return $parsedQuotes;
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

    private function onvistaDateTime(mixed $value): ?Carbon
    {
        return is_string($value) ? $this->dateTime($value) : null;
    }
}
