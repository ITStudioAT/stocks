<?php

namespace Tests\Unit;

use App\Ai\Agents\StockPriceResolver;
use App\Models\Depot;
use App\Models\StockHolding;
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

        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        config(['market-data.ai_fallback_enabled' => false]);
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

    public function test_onvista_parser_returns_all_structured_eur_quotes_and_selector_prefers_preferred_venue(): void
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
        $this->assertSame('180.22000000', $selection->selectedQuote?->quote->price);
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
        $this->travelTo(Carbon::parse('2026-06-03 11:30:00'));
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

    public function test_orchestrator_persists_selected_quote_history_and_updates_holding(): void
    {
        Http::fake([
            'www.tradegatebsx.com/*' => Http::response(
                '<html><body>Apple Inc. ISIN US0378331005 WKN 865985 Geld 180,10 EUR Brief 180,30 EUR 02.06.2026 13:25</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
            '*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
        ]);
        $holding = $this->holding();

        $result = app(WebMarketDataOrchestrator::class)->resolve($holding);
        $holding->refresh();

        $this->assertSame('realtime', $result->status);
        $this->assertSame('180.200000', $holding->latest_price);
        $this->assertSame('Tradegate Exchange', $holding->latest_price_source);
        $this->assertSame('indicative_mid', $holding->latest_price_type);
        $this->assertDatabaseHas('stock_price_quotes', [
            'stock_holding_id' => $holding->id,
            'source_key' => 'tradegate',
            'price' => '180.20000000',
            'validation_status' => 'valid',
        ]);
    }

    public function test_orchestrator_does_not_use_stale_onvista_quote_as_latest_available_price(): void
    {
        config(['market-data.ai_fallback_enabled' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 11:30:00'));
        Http::fake([
            'www.tradegatebsx.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/*' => Http::response(
                <<<'HTML'
                    <html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"FR0010251744","wkn":"LYX0A6"},"quoteList":{"list":[{"isoCurrency":"EUR","last":191.86,"datetimeLast":"2026-06-02T13:35:00.000+00:00","market":{"nameExchange":"Madrid SIBE","codeExchange":"XMAD"}},{"isoCurrency":"EUR","bid":190.7672,"ask":190.7672,"datetimeLast":"2026-06-01T06:00:00.000+00:00","market":{"nameExchange":"außerbörslich Deutschland","codeExchange":"GER"}}]}}}}}}</script></body></html>
                HTML,
                200,
                ['content-type' => 'text/html'],
            ),
            '*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
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
        $this->assertNull($holding->latestQuote);
    }

    public function test_orchestrator_extends_search_when_initial_quote_is_stale_or_suspicious(): void
    {
        config([
            'market-data.max_sources_per_holding' => 2,
            'market-data.max_extended_sources_per_holding' => 8,
        ]);
        $this->travelTo(Carbon::parse('2026-06-03 12:40:00'));
        Http::fake([
            'www.tradegatebsx.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/*' => Http::response(
                <<<'HTML'
                    <html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"FR0010251744","wkn":"LYX0A6"},"quoteList":{"list":[{"isoCurrency":"EUR","last":191.86,"datetimeLast":"2026-06-02T13:35:00.000+00:00","market":{"nameExchange":"Madrid SIBE","codeExchange":"XMAD"}}]}}}}}}</script></body></html>
                HTML,
                200,
                ['content-type' => 'text/html'],
            ),
            'www.finanzen.at/etf/amundi-ibex-35-etf-fr0010251744' => Http::response(
                '<html><body>Amundi IBEX 35 UCITS ETF Dist ISIN FR0010251744 WKN LYX0A6 aktueller Kurs 191,38 EUR Datum 03.06.2026 14:35:04 Vortag 191,28 EUR Boerse BX Swiss</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
            '*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
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

        $this->assertSame('delayed', $result->status);
        $this->assertSame('191.380000', $holding->latest_price);
        $this->assertSame('finanzen Markets Austria', $holding->latest_price_source);
        $this->assertSame('https://www.finanzen.at/etf/amundi-ibex-35-etf-fr0010251744', $holding->latest_price_source_url);
        $this->assertSame('2026-06-03 14:35:04', $holding->latest_price_as_of);
        $this->assertSame('BX Swiss', $holding->latestQuote?->venue);
        $this->assertContains('finanzen_markets', collect($result->attemptedSources)->pluck('sourceKey')->all());
    }

    public function test_orchestrator_uses_bx_swiss_when_finanzen_blocks_server_side_fetches(): void
    {
        config([
            'market-data.max_sources_per_holding' => 2,
            'market-data.max_extended_sources_per_holding' => 8,
        ]);
        $this->travelTo(Carbon::parse('2026-06-03 12:50:00'));
        Http::fake([
            'www.tradegatebsx.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/*' => Http::response(
                <<<'HTML'
                    <html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"FR0010251744","wkn":"LYX0A6"},"quoteList":{"list":[{"isoCurrency":"EUR","last":191.86,"datetimeLast":"2026-06-02T13:35:00.000+00:00","market":{"nameExchange":"Madrid SIBE","codeExchange":"XMAD"}}]}}}}}}</script></body></html>
                HTML,
                200,
                ['content-type' => 'text/html'],
            ),
            'www.finanzen.at/*' => Http::response('<html><body>Access Denied</body></html>', 403, ['content-type' => 'text/html']),
            'www.finanzen.net/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.bxswiss.com/*' => Http::response(
                '<html><body>ETF Amundi IBEX 35 UCITS ETF Dist Bid EUR 191.628 Vol 999 Ask EUR 192.480 Vol 999 Last update 14:48:54 CET ISIN FR0010251744 Symbol IBX35 Currency EUR</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
            '*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
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

        $this->assertSame('delayed', $result->status);
        $this->assertSame('192.054000', $holding->latest_price);
        $this->assertSame('BX Swiss', $holding->latest_price_source);
        $this->assertSame('https://www.bxswiss.com/instruments/FR0010251744', $holding->latest_price_source_url);
        $this->assertSame('2026-06-03 14:48:54', $holding->latest_price_as_of);
        $this->assertSame('indicative_mid', $holding->latest_price_type);
        $this->assertContains('bx_swiss', collect($result->attemptedSources)->pluck('sourceKey')->all());
    }

    public function test_orchestrator_can_use_ai_sdk_as_opt_in_last_resort_after_extended_sources(): void
    {
        config([
            'market-data.max_sources_per_holding' => 2,
            'market-data.max_extended_sources_per_holding' => 3,
            'market-data.ai_fallback_enabled' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-06-03 11:30:00'));
        Http::fake([
            'www.tradegatebsx.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/*' => Http::response(
                <<<'HTML'
                    <html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"FR0010251744","wkn":"LYX0A6"},"quoteList":{"list":[{"isoCurrency":"EUR","last":191.86,"datetimeLast":"2026-06-02T13:35:00.000+00:00","market":{"nameExchange":"Madrid SIBE","codeExchange":"XMAD"}}]}}}}}}</script></body></html>
                HTML,
                200,
                ['content-type' => 'text/html'],
            ),
            '*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
        ]);
        StockPriceResolver::fake([
            [
                'decimal_price' => '193.02',
                'currency' => 'EUR',
                'source_name' => 'Madrid exchange quote page',
                'source_url' => 'https://example.com/fr0010251744-madrid',
                'as_of' => '2026-06-03 13:28:00 Europe/Madrid',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
            ],
        ])->preventStrayPrompts();
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

        $this->assertSame('delayed', $result->status);
        $this->assertSame('193.020000', $holding->latest_price);
        $this->assertSame('Madrid exchange quote page', $holding->latest_price_source);
        $this->assertSame('https://example.com/fr0010251744-madrid', $holding->latest_price_source_url);
        $this->assertContains('ai_sdk_web_search', collect($result->attemptedSources)->pluck('sourceKey')->all());
        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('FR0010251744')
            && $prompt->contains('weak quotes'));
    }

    public function test_orchestrator_preserves_existing_price_when_no_source_has_a_valid_quote(): void
    {
        Http::fake(['*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html'])]);
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
        $depot = Depot::factory()->create(['is_active' => true]);

        return StockHolding::factory()->create([
            'depot_id' => $depot->id,
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
