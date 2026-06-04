<?php

namespace Tests\Unit;

use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use App\Services\WebMarketData\Parsers\BoerseStuttgartQuoteParser;
use App\Services\WebMarketData\Parsers\BxSwissParser;
use App\Services\WebMarketData\Parsers\FinanzenMarketsParser;
use App\Services\WebMarketData\Parsers\JustEtfQuoteParser;
use App\Services\WebMarketData\Parsers\OnvistaMarketsParser;
use App\Services\WebMarketData\Parsers\QuotrixQuoteParser;
use App\Services\WebMarketData\Parsers\TradegateQuoteParser;
use App\Services\WebMarketData\WebMarketDataOrchestrator;
use App\Services\WebMarketData\WebQuoteSelector;
use App\Services\WebMarketData\WebQuoteValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-02 11:30:00', 'UTC'));
        config(['market-data.ai_fallback_enabled' => false]);
        config(['services.eodhd.key' => 'test-token']);
        Cache::flush();
    }

    public function test_tradegate_parser_uses_bid_ask_midpoint_for_matching_eur_quote(): void
    {
        $quote = $this->parseFirst(new TradegateQuoteParser, 'tradegate', 'Tradegate Exchange', <<<'HTML'
            <html><body>Apple Inc. ISIN US0378331005 WKN 865985 Geld 180,10 EUR Brief 180,30 EUR Letzter 180,20 EUR 02.06.2026 13:25</body></html>
        HTML);

        $this->assertSame('US0378331005', $quote->isin);
        $this->assertSame('180.20000000', $quote->price);
        $this->assertSame('indicative_mid', $quote->priceType);
        $this->assertSame('EUR', $quote->currency);
        $this->assertSame('Tradegate', $quote->venue);
    }

    public function test_tradegate_parser_rejects_wrong_isin_and_non_eur_quotes(): void
    {
        $parser = new TradegateQuoteParser;
        $instrument = $this->instrument();
        $candidate = $this->candidate('tradegate', 'Tradegate Exchange');

        $wrongIsin = $parser->parse(
            '<html><body>ISIN US5949181045 Geld 180,10 EUR Brief 180,30 EUR 02.06.2026 13:25</body></html>',
            $candidate,
            $instrument,
            new ParserDiagnostics('tradegate'),
        );
        $nonEur = $parser->parse(
            '<html><body>ISIN US0378331005 Geld 180,10 USD Brief 180,30 USD 02.06.2026 13:25</body></html>',
            $candidate,
            $instrument,
            new ParserDiagnostics('tradegate'),
        );

        $this->assertSame([], $wrongIsin);
        $this->assertSame([], $nonEur);
    }

    public function test_onvista_parser_returns_all_structured_eur_quotes_and_selector_calculates_median(): void
    {
        $content = <<<'HTML'
            <html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"US0378331005","wkn":"865985"},"quoteList":{"list":[{"isoCurrency":"EUR","last":180.10,"datetimeLast":"2026-06-02T11:25:00.000+00:00","market":{"nameExchange":"Xetra","codeExchange":"GER"}},{"isoCurrency":"EUR","last":180.22,"datetimeLast":"2026-06-02T11:26:00.000+00:00","market":{"nameExchange":"Tradegate","codeExchange":"GAT"}}]}}}}}}</script></body></html>
        HTML;

        $candidate = $this->candidate('onvista_markets', 'onvista Markets', 'onvista_markets', 'multi_venue_portal');
        $instrument = $this->instrument(preferredVenue: 'Tradegate');
        $parsedQuotes = (new OnvistaMarketsParser)->parse($content, $candidate, $instrument, new ParserDiagnostics('onvista_markets'));
        $validatedQuotes = array_map(fn (ParsedQuote $quote) => app(WebQuoteValidator::class)->validate($quote, $instrument), $parsedQuotes);

        $selection = app(WebQuoteSelector::class)->select($validatedQuotes, $instrument, [$candidate]);

        $this->assertCount(2, $parsedQuotes);
        $this->assertSame('Tradegate', $selection->selectedQuote?->quote->venue);
        $this->assertSame('180.160000', $selection->selectedQuote?->quote->price);
        $this->assertSame('180.160000', $selection->median);
        $this->assertSame('180.160000', $selection->arithmeticMean);
        $this->assertSame('medium', $selection->confidence);
        $this->assertCount(2, $selection->usedQuotes);
    }

    public function test_known_public_web_parsers_extract_matching_eur_quotes(): void
    {
        $cases = [
            [new FinanzenMarketsParser, 'finanzen_markets', 'finanzen Markets', '<html><body>Boersenplaetze Apple Inc. ISIN US0378331005 WKN 865985 Boerse gettex Waehrung EUR Letzter 180,21 EUR Zeit 13:25:10 Datum 02.06.2026</body></html>', '180.21000000', 'gettex'],
            [new BxSwissParser, 'bx_swiss', 'BX Swiss', '<html><body>Apple Inc. ISIN US0378331005 Bid EUR 180.10 Vol 100 Ask EUR 180.30 Vol 100 Last update 13:25:10 CET</body></html>', '180.20000000', 'BX Swiss'],
            [new QuotrixQuoteParser, 'quotrix', 'Quotrix', '<html><body>Apple Inc. ISIN US0378331005 Geld 180,10 EUR Brief 180,30 EUR Stand 02.06.2026 13:25:10</body></html>', '180.20000000', 'Quotrix'],
            [new BoerseStuttgartQuoteParser, 'boerse_stuttgart', 'Boerse Stuttgart', '<html><body>Apple Inc. ISIN US0378331005 Geld 180,10 EUR Brief 180,30 EUR Kurszeit 13:25:10 02.06.2026</body></html>', '180.20000000', 'Boerse Stuttgart'],
        ];

        foreach ($cases as [$parser, $sourceKey, $sourceName, $content, $expectedPrice, $expectedVenue]) {
            $quote = $this->parseFirst($parser, $sourceKey, $sourceName, $content);

            $this->assertSame($expectedPrice, $quote->price);
            $this->assertSame($expectedVenue, $quote->venue);
            $this->assertSame('EUR', $quote->currency);
        }
    }

    public function test_justetf_nav_is_stored_but_not_treated_as_live_price(): void
    {
        $quote = $this->parseFirst(
            new JustEtfQuoteParser,
            'justetf',
            'justETF',
            '<html><body>Vanguard S&P 500 UCITS ETF ISIN US0378331005 NAV 180,21 EUR 02.06.2026 13:25</body></html>',
            instrumentType: 'ETF',
        );

        $validated = app(WebQuoteValidator::class)->validate($quote, $this->instrument(instrumentType: 'ETF'));

        $this->assertSame('nav', $quote->priceType);
        $this->assertSame('suspicious', $validated->validationStatus);
        $this->assertSame('closed_market', $validated->freshnessStatus);
        $this->assertContains('NAV is stored but not treated as a live exchange price.', $validated->validationErrors);
    }

    public function test_validator_marks_wide_spreads_price_jumps_and_missing_timestamps(): void
    {
        $instrument = $this->instrument(lastPrice: '100.000000');
        $validator = app(WebQuoteValidator::class);

        $wideSpread = $validator->validate($this->quote(price: '100.00000000', bid: '98.00000000', ask: '102.00000000'), $instrument);
        $priceJump = $validator->validate($this->quote(price: '140.00000000', last: '140.00000000'), $instrument);
        $missingTimestamp = $validator->validate($this->quote(price: '100.00000000', last: '100.00000000', asOf: null), $instrument);

        $this->assertSame('suspicious', $wideSpread->validationStatus);
        $this->assertContains('Spread exceeds warning threshold.', $wideSpread->validationErrors);
        $this->assertSame('suspicious', $priceJump->validationStatus);
        $this->assertContains('Price jump exceeds warning threshold and requires cross-check.', $priceJump->validationErrors);
        $this->assertSame('invalid', $missingTimestamp->validationStatus);
        $this->assertContains('Quote timestamp is missing.', $missingTimestamp->validationErrors);
    }

    public function test_selector_does_not_select_stale_quotes_as_latest_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 11:30:00', 'UTC'));
        $instrument = new InstrumentIdentity(
            symbol: 'LYXIB',
            name: 'Amundi IBEX 35 UCITS ETF Dist',
            isin: 'FR0010251744',
            wkn: 'LYX0A6',
            exchange: 'Madrid',
            mic: 'XMAD',
            instrumentType: 'ETF',
            country: null,
            currency: 'EUR',
            preferredVenue: null,
            preferredMic: null,
            preferredSourceKey: null,
            lastPrice: null,
        );
        $validator = app(WebQuoteValidator::class);
        $quotes = [
            $validator->validate(new ParsedQuote(
                sourceKey: 'onvista_markets',
                sourceName: 'onvista Markets',
                sourceUrl: 'https://www.onvista.de/etf/FR0010251744',
                sourceQuality: 'multi_venue_portal',
                venue: 'Madrid SIBE',
                isin: 'FR0010251744',
                wkn: 'LYX0A6',
                currency: 'EUR',
                last: '191.86000000',
                price: '191.86000000',
                priceType: 'last',
                asOf: Carbon::parse('2026-06-02 15:35:00', 'Europe/Madrid'),
                fetchedAt: now(),
            ), $instrument),
            $validator->validate(new ParsedQuote(
                sourceKey: 'onvista_markets',
                sourceName: 'onvista Markets',
                sourceUrl: 'https://www.onvista.de/etf/FR0010251744',
                sourceQuality: 'multi_venue_portal',
                venue: 'außerbörslich Deutschland',
                isin: 'FR0010251744',
                wkn: 'LYX0A6',
                currency: 'EUR',
                bid: '190.76720000',
                ask: '190.76720000',
                price: '190.76720000',
                priceType: 'indicative_mid',
                asOf: Carbon::parse('2026-06-01 06:00:00', 'Europe/Berlin'),
                fetchedAt: now(),
            ), $instrument),
            $validator->validate(new ParsedQuote(
                sourceKey: 'tradegate',
                sourceName: 'Tradegate Exchange',
                sourceUrl: 'https://www.tradegatebsx.com/orderbuch.php?isin=FR0010251744',
                sourceQuality: 'official_venue',
                venue: 'Tradegate',
                isin: 'FR0010251744',
                wkn: 'LYX0A6',
                currency: 'EUR',
                bid: '279.65000000',
                ask: '280.10000000',
                price: '279.87500000',
                priceType: 'indicative_mid',
                asOf: Carbon::parse('2026-06-03 08:00:00', 'Europe/Berlin'),
                fetchedAt: now(),
            ), $instrument),
        ];
        $selector = app(WebQuoteSelector::class);

        $diagnosticResult = $selector->select($quotes, $instrument, [], applyCrossCheck: false);
        $finalResult = $selector->select($quotes, $instrument, []);

        $this->assertNull($diagnosticResult->selectedQuote);
        $this->assertSame('unavailable', $diagnosticResult->status);
        $this->assertNull($finalResult->selectedQuote);
        $this->assertSame('unavailable', $finalResult->status);
    }

    public function test_selector_calculates_median_from_valid_quotes_and_excludes_outliers(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00', 'UTC'));
        config(['market-data.outlier_tolerance_pct' => 3.0]);
        $instrument = $this->instrument();
        $validator = app(WebQuoteValidator::class);
        $asOf = Carbon::parse('2026-06-02 13:25:00', 'Europe/Berlin');
        $quotes = [
            $validator->validate($this->quote(price: '66.30000000', last: '66.30000000', asOf: $asOf), $instrument),
            $validator->validate($this->quote(price: '66.40000000', last: '66.40000000', asOf: $asOf), $instrument),
            $validator->validate($this->quote(price: '90.00000000', last: '90.00000000', asOf: $asOf), $instrument),
        ];

        $selection = app(WebQuoteSelector::class)->select($quotes, $instrument, []);

        $this->assertSame('realtime', $selection->status);
        $this->assertSame('66.350000', $selection->selectedQuote?->quote->price);
        $this->assertSame('66.350000', $selection->median);
        $this->assertSame('66.350000', $selection->arithmeticMean);
        $this->assertSame('2 quotes', $selection->selectedQuote?->quote->sourceName);
        $this->assertCount(2, $selection->usedQuotes);
        $this->assertSame('suspicious', $quotes[2]->validationStatus);
        $this->assertContains('Excluded from median calculation as a price outlier.', $quotes[2]->validationErrors);
    }

    public function test_orchestrator_persists_selected_quote_history_and_updates_holding(): void
    {
        Http::fake([
            'eodhd.com/api/real-time/AAPL.US*' => Http::response([
                'code' => 'AAPL.US',
                'timestamp' => Carbon::parse('2026-06-02 11:29:00', 'UTC')->timestamp,
                'close' => 180.20,
            ]),
        ]);
        $holding = $this->holding();

        $result = app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('realtime', $result->status);
        $this->assertSame('180.20000000', $holding->latestStockPrice?->price);
        $this->assertSame('EODHD real-time', $holding->latestStockPrice?->source_name);
        $this->assertSame('last', $holding->latestStockPrice?->price_type);
        $this->assertStringNotContainsString('api_token', (string) $holding->latestStockPrice?->source_url);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'price' => '180.20000000',
            'validation_status' => 'valid',
            'as_of' => '2026-06-02 11:29:00',
        ]);
    }

    public function test_orchestrator_reuses_the_same_app_wide_price_for_repeated_watchlist_refreshes(): void
    {
        Http::fake([
            'eodhd.com/api/real-time/AAPL.US*' => Http::response([
                'code' => 'AAPL.US',
                'timestamp' => Carbon::parse('2026-06-02 11:29:00', 'UTC')->timestamp,
                'close' => 180.20,
            ]),
        ]);
        $holding = $this->holding();

        app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();
        $firstStockPriceId = $holding->latest_stock_price_id;

        app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame($firstStockPriceId, $holding->latest_stock_price_id);
        $this->assertSame(1, StockPrice::query()->count());
    }

    public function test_orchestrator_marks_holding_unavailable_when_eodhd_has_no_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 11:30:00', 'UTC'));
        Http::fake([
            'eodhd.com/api/real-time/LYXIB.XMAD*' => Http::response([
                'status' => 'error',
                'message' => 'No data available.',
            ]),
        ]);
        $holding = $this->holding([
            'symbol' => 'LYXIB',
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'isin' => 'FR0010251744',
            'wkn' => 'LYX0A6',
            'exchange' => 'Madrid',
            'mic_code' => 'XMAD',
            'instrument_type' => 'ETF',
            'currency' => 'EUR',
        ]);

        $result = app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('unavailable', $result->status);
        $this->assertNull($holding->latest_price);
        $this->assertNull($holding->latest_price_source);
        $this->assertNull($holding->latest_price_source_url);
        $this->assertSame('unavailable_now', $holding->price_status);
        $this->assertNull($holding->latestStockPrice);
    }

    public function test_orchestrator_preserves_existing_price_when_no_source_has_a_valid_quote(): void
    {
        Http::fake([
            'eodhd.com/api/real-time/AAPL.US*' => Http::response([
                'status' => 'error',
                'message' => 'No data available.',
            ]),
        ]);
        $holding = $this->holding([
            'latest_price' => '177.000000',
            'latest_price_source' => 'Previous verified source',
            'latest_price_source_url' => 'https://example.com/old',
            'latest_price_as_of' => '2026-06-02 10:00:00',
        ]);

        $result = app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('177.000000', $holding->latest_price);
        $this->assertSame('stale', $holding->price_status);
        $this->assertSame('Previous verified source', $holding->latest_price_source);
    }

    public function test_orchestrator_never_overwrites_a_newer_existing_source_time_with_an_older_quote(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 16:58:00', 'UTC'));
        Http::fake([
            'eodhd.com/api/real-time/EXXX.XETRA*' => Http::response([
                'code' => 'EXXX.XETRA',
                'timestamp' => Carbon::parse('2026-06-03 15:35:00', 'UTC')->timestamp,
                'close' => 66.485,
            ]),
        ]);
        $holding = $this->holding([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'instrument_type' => 'ETF',
            'currency' => 'EUR',
            'latest_price' => '66.900000',
            'latest_price_source' => 'Previous newer source',
            'latest_price_source_url' => 'https://example.com/newer',
            'latest_price_as_of' => '2026-06-03 16:15:00',
            'latest_price_type' => 'last',
            'price_status' => 'delayed',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);

        $result = app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('closed_market', $result->status);
        $this->assertSame('66.900000', $holding->latest_price);
        $this->assertSame('Previous newer source', $holding->latest_price_source);
        $this->assertSame('https://example.com/newer', $holding->latest_price_source_url);
        $this->assertSame('2026-06-03 16:15:00', $holding->latest_price_as_of);
        $this->assertDatabaseHas('stock_prices', [
            'source_key' => 'eodhd_realtime',
            'as_of' => '2026-06-03 15:35:00',
        ]);
    }

    private function parseFirst(
        object $parser,
        string $sourceKey,
        string $sourceName,
        string $content,
        string $instrumentType = 'Common Stock',
    ): ParsedQuote {
        $quotes = $parser->parse(
            $content,
            $this->candidate($sourceKey, $sourceName),
            $this->instrument(instrumentType: $instrumentType),
            new ParserDiagnostics($sourceKey),
        );

        $this->assertNotEmpty($quotes);

        return $quotes[0];
    }

    private function candidate(
        string $sourceKey,
        string $sourceName,
        ?string $parserKey = null,
        string $quality = 'official_venue',
    ): WebSourceCandidate {
        return new WebSourceCandidate(
            sourceKey: $sourceKey,
            sourceName: $sourceName,
            url: "https://example.com/{$sourceKey}",
            parserKey: $parserKey ?? $sourceKey,
            quality: $quality,
            priority: 10,
        );
    }

    private function instrument(
        ?string $preferredVenue = null,
        string $instrumentType = 'Common Stock',
        ?string $lastPrice = null,
    ): InstrumentIdentity {
        return new InstrumentIdentity(
            symbol: 'AAPL',
            name: $instrumentType === 'ETF' ? 'Vanguard S&P 500 UCITS ETF' : 'Apple Inc.',
            isin: 'US0378331005',
            wkn: '865985',
            exchange: 'Tradegate',
            mic: null,
            instrumentType: $instrumentType,
            country: 'United States',
            currency: 'EUR',
            preferredVenue: $preferredVenue,
            preferredMic: null,
            preferredSourceKey: null,
            lastPrice: $lastPrice,
        );
    }

    private function quote(
        string $price,
        ?string $bid = null,
        ?string $ask = null,
        ?string $last = null,
        ?Carbon $asOf = null,
    ): ParsedQuote {
        return new ParsedQuote(
            sourceKey: 'tradegate',
            sourceName: 'Tradegate Exchange',
            sourceUrl: 'https://example.com/tradegate',
            sourceQuality: 'official_venue',
            venue: 'Tradegate',
            isin: 'US0378331005',
            wkn: '865985',
            symbol: 'AAPL',
            currency: 'EUR',
            bid: $bid,
            ask: $ask,
            last: $last,
            price: $price,
            priceType: $bid !== null && $ask !== null ? 'indicative_mid' : 'last',
            asOf: func_num_args() >= 5 ? $asOf : Carbon::parse('2026-06-02 13:25:00', 'Europe/Berlin'),
            fetchedAt: now(),
            freshnessStatus: 'fresh',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function holding(array $attributes = []): StockHolding
    {
        return StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'wkn' => '865985',
            'exchange' => 'Tradegate',
            'instrument_type' => 'Common Stock',
            'currency' => 'EUR',
            'latest_price' => null,
            ...$attributes,
        ]);
    }
}
