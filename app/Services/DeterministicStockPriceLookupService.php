<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DeterministicStockPriceLookupService
{
    private const EuronextGatewayAuthKey = '256f0720127269acfcb390b5adef10c242469c41afd08426744324bee3e2d75a';

    public function __construct(
        private StockPriceFreshness $stockPriceFreshness,
    ) {}

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array{price: string, currency: 'EUR', source: string, source_url: string, as_of: ?string, trading_times: ?string}|null
     */
    public function latestPrice(array $instrument): ?array
    {
        foreach ($this->sourceCandidates($instrument) as $candidate) {
            if ($this->isSearchUrl($candidate['url'])) {
                continue;
            }

            $content = $this->fetch($candidate['fetch_url'] ?? $candidate['url']);

            if ($content === null) {
                continue;
            }

            $result = $this->parseContent($content, $instrument);

            if ($result === null) {
                continue;
            }

            return [
                'price' => $result['price'],
                'currency' => 'EUR',
                'source' => $candidate['name'],
                'source_url' => $candidate['url'],
                'as_of' => $result['as_of'],
                'trading_times' => $result['trading_times'],
            ];
        }

        return null;
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array<int, array{name: string, url: string, fetch_url?: string}>
     */
    private function sourceCandidates(array $instrument): array
    {
        $isin = $this->identifier(Arr::get($instrument, 'isin'));
        $euronextMic = $this->euronextMic(Arr::get($instrument, 'mic_code'));
        $providedSourceUrl = $this->url(Arr::get($instrument, 'source_url'));

        $candidates = collect();

        if (
            $providedSourceUrl !== null
            && ! $this->isSearchUrl($providedSourceUrl)
            && $this->isSupportedPreviousSourceUrl($providedSourceUrl)
        ) {
            $candidates->push([
                'name' => 'Previous verified source',
                'url' => $providedSourceUrl,
            ]);

            $companionUrl = $this->companionSourceUrl($providedSourceUrl);

            if ($companionUrl !== null) {
                $candidates->push($companionUrl);
            }
        }

        if ($isin !== null) {
            $encodedIsin = rawurlencode($isin);
            $euronextCandidates = $euronextMic === null ? [] : [
                [
                    'name' => 'Euronext Live',
                    'url' => $this->euronextPageUrl($encodedIsin, $euronextMic),
                    'fetch_url' => $this->euronextGatewayUrl($encodedIsin, $euronextMic),
                ],
            ];

            $candidates = $candidates->merge([
                ...$euronextCandidates,
                [
                    'name' => 'Borsa Italiana',
                    'url' => "https://www.borsaitaliana.it/borsa/etf/listino-ufficiale.html?isin={$encodedIsin}&lang=en",
                ],
                [
                    'name' => 'Tradegate Exchange',
                    'url' => "https://www.tradegate.de/orderbuch.php?isin={$encodedIsin}",
                ],
                [
                    'name' => 'justETF Austria',
                    'url' => "https://www.justetf.com/at/etf-profile.html?isin={$encodedIsin}",
                ],
                [
                    'name' => 'justETF Germany',
                    'url' => "https://www.justetf.com/de/etf-profile.html?isin={$encodedIsin}",
                ],
                [
                    'name' => 'justETF International',
                    'url' => "https://www.justetf.com/en/etf-profile.html?isin={$encodedIsin}",
                ],
                [
                    'name' => 'extraETF Austria',
                    'url' => "https://extraetf.com/at/etf-profile/{$encodedIsin}",
                ],
                [
                    'name' => 'onvista ETF',
                    'url' => "https://www.onvista.de/etf/{$encodedIsin}",
                ],
            ]);
        }

        return $candidates
            ->unique(fn (array $candidate): string => $candidate['fetch_url'] ?? $candidate['url'])
            ->values()
            ->all();
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::accept('text/html,application/xhtml+xml,application/json')
                ->withUserAgent('Stocks Portfolio Price Lookup/1.0')
                ->connectTimeout(5)
                ->timeout(10)
                ->retry(1, 250)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok() || ! $this->isReadableResponse($response)) {
            return null;
        }

        $body = $response->body();

        return trim($body) === '' ? null : Str::limit($body, 1_000_000, '');
    }

    private function isReadableResponse(Response $response): bool
    {
        $contentType = Str::lower((string) $response->header('content-type'));

        return $contentType === ''
            || Str::contains($contentType, ['html', 'json', 'text', 'javascript']);
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array{price: string, as_of: ?string, trading_times: ?string}|null
     */
    private function parseContent(string $content, array $instrument): ?array
    {
        if (Str::contains($content, '__NEXT_DATA__')) {
            return $this->parseOnvistaNextData($content, $instrument);
        }

        $searchableContent = $this->searchableContent($content);

        if (! $this->containsInstrumentIdentifier($searchableContent, $instrument)) {
            return null;
        }

        if (! $this->containsEurCurrency($searchableContent)) {
            return null;
        }

        $price = $this->extractPrice($searchableContent);

        if ($price === null) {
            return null;
        }

        $asOf = $this->extractAsOf($searchableContent);
        $tradingTimes = $this->extractTradingTimes($searchableContent) ?? $this->tradingTimesForInstrument($instrument);

        if (! $this->stockPriceFreshness->isFresh($asOf, $tradingTimes)) {
            return null;
        }

        return [
            'price' => $price,
            'as_of' => $asOf,
            'trading_times' => $tradingTimes,
        ];
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array{price: string, as_of: ?string, trading_times: ?string}|null
     */
    private function parseOnvistaNextData(string $content, array $instrument): ?array
    {
        if (! preg_match('~<script id="__NEXT_DATA__" type="application/json">(?<json>.*?)</script>~s', $content, $matches)) {
            return null;
        }

        $data = json_decode(html_entity_decode($matches['json'], ENT_QUOTES | ENT_HTML5), true);

        if (! is_array($data)) {
            return null;
        }

        $snapshot = Arr::get($data, 'props.pageProps.data.snapshot');

        if (! is_array($snapshot) || ! $this->onvistaInstrumentMatches($snapshot, $instrument)) {
            return null;
        }

        $quote = $this->onvistaQuote($snapshot, $instrument);

        if ($quote === null) {
            return null;
        }

        $price = $this->decimalPrice(Arr::get($quote, 'last'));
        $asOf = $this->onvistaDateTime(Arr::get($quote, 'datetimeLast'), $quote);
        $tradingTimes = $this->tradingTimesForInstrument($instrument, Arr::get($quote, 'market'));

        if ($price === null || $this->nullableCurrency(Arr::get($quote, 'isoCurrency')) !== 'EUR') {
            return null;
        }

        if (! $this->stockPriceFreshness->isFresh($asOf, $tradingTimes)) {
            return null;
        }

        return [
            'price' => $price,
            'as_of' => $asOf,
            'trading_times' => $tradingTimes,
        ];
    }

    private function searchableContent(string $content): string
    {
        $normalizedContent = str_replace("\xE2\x82\xAC", ' EUR ', $content);
        $withoutScripts = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' $1 ', $normalizedContent) ?? $normalizedContent;
        $withoutStyles = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $withoutScripts) ?? $withoutScripts;
        $decoded = html_entity_decode(strip_tags($withoutStyles), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\xE2\x82\xAC", ' EUR ', $decoded);

        return trim(preg_replace('/\s+/u', ' ', "{$decoded} {$normalizedContent}") ?? "{$decoded} {$normalizedContent}");
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     */
    private function containsInstrumentIdentifier(string $content, array $instrument): bool
    {
        $upperContent = Str::upper($content);

        foreach (['isin', 'wkn'] as $key) {
            $identifier = $this->identifier(Arr::get($instrument, $key));

            if ($identifier !== null && Str::contains($upperContent, $identifier)) {
                return true;
            }
        }

        return false;
    }

    private function containsEurCurrency(string $content): bool
    {
        return Str::contains(Str::upper($content), [' EUR', 'EUR ', '"EUR"', "'EUR'", '&EURO;', 'EURO']);
    }

    private function extractPrice(string $content): ?string
    {
        foreach ($this->pricePatterns() as $pattern) {
            if (! preg_match($pattern, $content, $matches)) {
                continue;
            }

            $price = $this->decimalPrice($matches['price'] ?? null);

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function pricePatterns(): array
    {
        $price = '(?<price>\d{1,4}(?:[.,]\d{3})*(?:[.,]\d{1,6})|\d{1,6}(?:[.,]\d{1,6})?)';

        return [
            "/[\"']lastPx[\"']\s*:\s*[\"']?{$price}[\"']?/iu",
            "/\bheute,?\s+\d{1,2}:\d{2}(?::\d{2})?.{0,160}?(?:&euro;|EUR|EURO).{0,80}?(?<![\d.,]){$price}\s*(?:&euro;|EUR|EURO)\b/iu",
            "/(?:last|latest|current|official|close|closing|trade|price|kurs|preis|letzter|last-price|lastPrice)[^0-9]{0,80}(?:&euro;|EUR|EURO)?\s*{$price}\s*(?:&euro;|EUR|EURO)\b/iu",
            "/(?:last|latest|current|official|close|closing|trade|price|kurs|preis|letzter|last-price|lastPrice)[^0-9]{0,80}(?:&euro;|EUR|EURO)\s*{$price}/iu",
            "/[\"'](?:last|latest|current|official|close|closing|trade|price|kurs|preis|letzter|lastPrice|last_price)[\"']\s*:\s*[\"']?{$price}[\"']?[^{}]{0,160}[\"'](?:currency|currencyCode)[\"']\s*:\s*[\"']EUR[\"']/iu",
            "/[\"'](?:currency|currencyCode)[\"']\s*:\s*[\"']EUR[\"'][^{}]{0,160}[\"'](?:last|latest|current|official|close|closing|trade|price|kurs|preis|letzter|lastPrice|last_price)[\"']\s*:\s*[\"']?{$price}[\"']?/iu",
            "/(?:&euro;|EUR|EURO)\s*{$price}\s*(?:last|latest|current|official|close|closing|trade|price|kurs|preis|letzter)?/iu",
        ];
    }

    private function extractTradingTimes(string $content): ?string
    {
        foreach ($this->tradingTimesPatterns() as $pattern) {
            if (! preg_match($pattern, $content, $matches)) {
                continue;
            }

            return $this->normalizeTradingTimes($matches['trading_times']);
        }

        return null;
    }

    private function normalizeTradingTimes(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/(?<trading_times>.*?(?:CET|CEST|UTC|Europe\/[A-Za-z_]+|local time))/iu', $value, $matches)) {
            return Str::limit(trim($matches['trading_times']), 255, '');
        }

        if (preg_match('/(?<trading_times>.*?\d{1,2}:\d{2}\s*(?:-|to|until|bis)\s*\d{1,2}:\d{2})/iu', $value, $matches)) {
            return Str::limit(trim($matches['trading_times']), 255, '');
        }

        return Str::limit($value, 255, '');
    }

    /**
     * @return array<int, string>
     */
    private function tradingTimesPatterns(): array
    {
        $days = '(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?|Mo|Di|Mi|Do|Fr|Sa|So)';
        $timeRange = '\d{1,2}:\d{2}\s*(?:-|to|until|bis)\s*\d{1,2}:\d{2}';
        $timezone = '(?:CET|CEST|UTC|Europe\/[A-Za-z_]+|local time)';

        return [
            "/(?:trading hours|trading times|market hours|trading session|handelszeiten|boersenzeiten)[:\s-]*(?<trading_times>.{0,80}{$timeRange}.{0,80}(?:{$timezone})?)/iu",
            "/(?<trading_times>{$days}(?:\s*(?:-|to|until|bis)\s*{$days})?.{0,40}{$timeRange}.{0,40}(?:{$timezone})?)/iu",
            "/[\"'](?:tradingHours|trading_hours|tradingTimes|trading_times|marketHours|market_hours)[\"']\s*:\s*[\"'](?<trading_times>[^\"']{1,255})[\"']/iu",
        ];
    }

    private function extractAsOf(string $content): ?string
    {
        if (preg_match('~["\']currInstrSess["\']\s*:\s*\{.*?["\']dateTime["\']\s*:\s*["\'](?<datetime>\d{8}-\d{2}:\d{2}:\d{2})["\']~isu', $content, $matches)) {
            return $this->euronextDateTime($matches['datetime']);
        }

        if (preg_match('/\bheute,?\s*(?<time>\d{1,2}:\d{2}(?::\d{2})?)/iu', $content, $matches)) {
            return Carbon::now('Europe/Berlin')->format('d.m.Y').' '.$matches['time'].' Europe/Berlin';
        }

        $date = '\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{4}-\d{2}-\d{2}';
        $time = '\d{1,2}:\d{2}(?::\d{2})?';

        if (preg_match("/(?:datum|date)[^0-9]{0,40}(?<date>{$date}).{0,120}(?:zeit|time)[^0-9]{0,40}(?<time>{$time})/iu", $content, $matches)) {
            return "{$matches['date']} {$matches['time']}";
        }

        if (preg_match("/(?:zeit|time)[^0-9]{0,40}(?<time>{$time}).{0,120}(?:datum|date)[^0-9]{0,40}(?<date>{$date})/iu", $content, $matches)) {
            return "{$matches['date']} {$matches['time']}";
        }

        $dateTimePattern = '/(?<as_of>\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?(?:\s*(?:CET|CEST|UTC|Europe\/[A-Za-z_]+))?|\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?)/iu';

        if (! preg_match($dateTimePattern, $content, $matches)) {
            return null;
        }

        return Str::limit(trim($matches['as_of']), 255, '');
    }

    private function euronextDateTime(string $dateTime): string
    {
        if (! preg_match('/^(?<year>\d{4})(?<month>\d{2})(?<day>\d{2})-(?<time>\d{2}:\d{2}:\d{2})$/', $dateTime, $matches)) {
            return $dateTime;
        }

        return "{$matches['year']}-{$matches['month']}-{$matches['day']} {$matches['time']} Europe/Paris";
    }

    private function decimalPrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalSeparator = $lastComma !== false && ($lastDot === false || $lastComma > $lastDot) ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandsSeparator, '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        return number_format((float) $normalized, 6, '.', '');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     */
    private function onvistaInstrumentMatches(array $snapshot, array $instrument): bool
    {
        $isin = $this->identifier(Arr::get($instrument, 'isin'));
        $wkn = $this->identifier(Arr::get($instrument, 'wkn'));

        return ($isin !== null && $isin === $this->identifier(Arr::get($snapshot, 'instrument.isin')))
            || ($wkn !== null && $wkn === $this->identifier(Arr::get($snapshot, 'instrument.wkn')));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array<string, mixed>|null
     */
    private function onvistaQuote(array $snapshot, array $instrument): ?array
    {
        $quotes = Arr::get($snapshot, 'quoteList.list');

        if (! is_array($quotes) || $quotes === []) {
            $quote = Arr::get($snapshot, 'quote');

            return is_array($quote) ? $quote : null;
        }

        $marketCodes = $this->onvistaMarketCodes($instrument);

        foreach ($quotes as $quote) {
            if (! is_array($quote) || $this->nullableCurrency(Arr::get($quote, 'isoCurrency')) !== 'EUR') {
                continue;
            }

            if ($marketCodes === [] || $this->onvistaQuoteMatchesMarket($quote, $marketCodes)) {
                return $quote;
            }
        }

        foreach ($quotes as $quote) {
            if (is_array($quote) && $this->nullableCurrency(Arr::get($quote, 'isoCurrency')) === 'EUR') {
                return $quote;
            }
        }

        return null;
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @return array<int, string>
     */
    private function onvistaMarketCodes(array $instrument): array
    {
        $mic = $this->identifier(Arr::get($instrument, 'mic_code'));
        $exchange = Str::upper((string) Arr::get($instrument, 'exchange', ''));

        return match (true) {
            $mic === 'XETR' || Str::contains($exchange, ['XETRA', 'XETR']) => ['GER', 'XETRA'],
            $mic === 'XSTU' || Str::contains($exchange, ['STUTTGART', 'XSTU']) => ['STU', 'STUTTGART'],
            $mic === 'XMAD' || Str::contains($exchange, ['MADRID', 'XMAD']) => ['MCE', 'SIBE', 'MADRID'],
            $mic === 'XPAR' || Str::contains($exchange, ['PARIS', 'XPAR']) => ['PAR', 'PARIS'],
            $mic === 'MTAA' || $mic === 'XMIL' || Str::contains($exchange, ['MILAN', 'MILANO', 'MTAA', 'XMIL']) => ['MIL', 'MILAN'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<int, string>  $marketCodes
     */
    private function onvistaQuoteMatchesMarket(array $quote, array $marketCodes): bool
    {
        $market = Str::upper(implode(' ', array_filter([
            Arr::get($quote, 'market.codeExchange'),
            Arr::get($quote, 'market.nameExchange'),
            Arr::get($quote, 'market.name'),
        ], fn (mixed $value): bool => is_string($value) || is_numeric($value))));

        return Str::contains($market, $marketCodes);
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function onvistaDateTime(mixed $value, array $quote): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timezone = $this->timezoneForMarket(Arr::get($quote, 'market'));

        try {
            return Carbon::parse($value)->setTimezone($timezone)->format('Y-m-d H:i:s').' '.$timezone;
        } catch (Throwable) {
            return null;
        }
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    private function euronextMic(mixed $value): ?string
    {
        $mic = $this->identifier($value);

        if ($mic === null) {
            return null;
        }

        return in_array($mic, ['XPAR', 'XAMS', 'XBRU', 'XLIS', 'XDUB', 'XOSL', 'XMIL', 'MTAA'], true) ? $mic : null;
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}  $instrument
     * @param  array<string, mixed>|null  $market
     */
    private function tradingTimesForInstrument(array $instrument, ?array $market = null): ?string
    {
        $existingTradingTimes = $this->nullableString(Arr::get($instrument, 'trading_times'));
        $timezone = $this->timezoneForMarket($market, $instrument);
        $mic = $this->identifier(Arr::get($instrument, 'mic_code'));
        $exchange = Str::upper((string) Arr::get($instrument, 'exchange', ''));
        $marketText = Str::upper(implode(' ', array_filter([
            Arr::get($market, 'codeExchange'),
            Arr::get($market, 'nameExchange'),
            Arr::get($market, 'name'),
        ], fn (mixed $value): bool => is_string($value) || is_numeric($value))));

        if ($mic === 'XSTU' || Str::contains($exchange, ['STUTTGART', 'XSTU']) || Str::contains($marketText, ['STU', 'STUTTGART'])) {
            return 'Monday-Friday 08:00-22:00 Europe/Berlin';
        }

        if (Str::contains($exchange, ['GETTEX']) || Str::contains($marketText, ['TRO', 'GETTEX', 'TRADEGATE', 'GAT'])) {
            return 'Monday-Friday 08:00-22:00 Europe/Berlin';
        }

        if (
            $mic !== null
            || $exchange !== ''
            || $marketText !== ''
        ) {
            return "Monday-Friday 09:00-17:30 {$timezone}";
        }

        return $existingTradingTimes;
    }

    /**
     * @param  array<string, mixed>|null  $market
     * @param  array{symbol?: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string, trading_times?: ?string}|null  $instrument
     */
    private function timezoneForMarket(?array $market = null, ?array $instrument = null): string
    {
        $mic = $this->identifier(Arr::get($instrument, 'mic_code'));
        $exchange = Str::upper((string) Arr::get($instrument, 'exchange', ''));
        $marketText = Str::upper(implode(' ', array_filter([
            Arr::get($market, 'codeExchange'),
            Arr::get($market, 'nameExchange'),
            Arr::get($market, 'name'),
        ], fn (mixed $value): bool => is_string($value) || is_numeric($value))));

        return match (true) {
            $mic === 'XMAD' || Str::contains($exchange, ['MADRID', 'XMAD']) || Str::contains($marketText, ['MCE', 'SIBE', 'MADRID']) => 'Europe/Madrid',
            $mic === 'XPAR' || Str::contains($exchange, ['PARIS', 'XPAR']) || Str::contains($marketText, ['PAR', 'PARIS']) => 'Europe/Paris',
            $mic === 'MTAA' || $mic === 'XMIL' || Str::contains($exchange, ['MILAN', 'MILANO', 'MTAA', 'XMIL']) || Str::contains($marketText, ['MIL', 'MILAN']) => 'Europe/Rome',
            default => 'Europe/Berlin',
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    private function nullableCurrency(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : Str::upper($value);
    }

    private function euronextPageUrl(string $encodedIsin, string $mic): string
    {
        return "https://live.euronext.com/en/product/etfs/{$encodedIsin}-{$mic}";
    }

    private function euronextGatewayUrl(string $encodedIsin, string $mic): string
    {
        return 'https://gateway.euronext.com/api/instrumentDetail?'.http_build_query([
            'code' => $encodedIsin,
            'codification' => 'ISIN',
            'exchCode' => $mic,
            'sessionQuality' => 'RT',
            'view' => 'FULL',
            'authKey' => self::EuronextGatewayAuthKey,
            'format' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return $value;
    }

    private function isSearchUrl(string $url): bool
    {
        $parts = parse_url($url);
        $path = Str::lower((string) ($parts['path'] ?? ''));
        $query = Str::lower((string) ($parts['query'] ?? ''));

        return Str::contains($path, ['/search', '/suche', 'search_instruments'])
            || Str::contains($query, ['query=', 'q=', 'search=', 'searchvalue=']);
    }

    private function isSupportedPreviousSourceUrl(string $url): bool
    {
        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return in_array($host, [
            'live.euronext.com',
            'www.borsaitaliana.it',
            'borsaitaliana.it',
            'www.tradegate.de',
            'tradegate.de',
            'www.justetf.com',
            'justetf.com',
            'extraetf.com',
            'www.extraetf.com',
            'www.onvista.de',
            'onvista.de',
            'www.finanzen.at',
            'finanzen.at',
            'www.boerse-frankfurt.de',
            'boerse-frankfurt.de',
            'www.wienerborse.at',
            'wienerborse.at',
            'www.boerse-stuttgart.de',
            'boerse-stuttgart.de',
            'www.gettex.de',
            'gettex.de',
            'www.ls-x.de',
            'ls-x.de',
            'www.boerse-duesseldorf.de',
            'boerse-duesseldorf.de',
            'www.boerse-hamburg.de',
            'boerse-hamburg.de',
            'www.boerse-hannover.de',
            'boerse-hannover.de',
        ], true);
    }

    /**
     * @return array{name: string, url: string, fetch_url?: string}|null
     */
    private function companionSourceUrl(string $url): ?array
    {
        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (in_array($host, ['live.euronext.com'], true) && preg_match('~^/[a-z]{2}/product/etfs/(?<isin>[A-Z0-9]{12})-(?<mic>[A-Z0-9]{4})$~i', $path, $matches)) {
            $isin = rawurlencode(Str::upper($matches['isin']));
            $mic = $this->euronextMic($matches['mic']);

            if ($mic === null) {
                return null;
            }

            return [
                'name' => 'Euronext Live',
                'url' => $url,
                'fetch_url' => $this->euronextGatewayUrl($isin, $mic),
            ];
        }

        if (! in_array($host, ['www.finanzen.at', 'finanzen.at'], true)) {
            return null;
        }

        if (! preg_match('~^/etf/(?!boersenplaetze/)(?<slug>[^?#]+)$~', $path, $matches)) {
            return null;
        }

        return [
            'name' => 'finanzen.at Boersenplaetze',
            'url' => "https://www.finanzen.at/etf/boersenplaetze/{$matches['slug']}",
        ];
    }
}
