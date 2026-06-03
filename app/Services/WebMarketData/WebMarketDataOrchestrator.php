<?php

namespace App\Services\WebMarketData;

use App\Models\StockHolding;
use App\Models\StockHoldingSourceCandidate;
use App\Models\StockPriceQuote;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ParserDiagnostics;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use App\Services\WebMarketData\Parsers\ArivaQuoteParser;
use App\Services\WebMarketData\Parsers\BoerseDeQuoteParser;
use App\Services\WebMarketData\Parsers\BoerseStuttgartQuoteParser;
use App\Services\WebMarketData\Parsers\DeutscheBoerseLiveParser;
use App\Services\WebMarketData\Parsers\EuronextLiveParser;
use App\Services\WebMarketData\Parsers\FinanzenMarketsParser;
use App\Services\WebMarketData\Parsers\JustEtfQuoteParser;
use App\Services\WebMarketData\Parsers\OnvistaMarketsParser;
use App\Services\WebMarketData\Parsers\QuotrixQuoteParser;
use App\Services\WebMarketData\Parsers\TradegateQuoteParser;
use App\Services\WebMarketData\Parsers\WebQuoteParser;
use App\Services\WebMarketData\Parsers\WienerBoerseParser;
use Illuminate\Support\Carbon;

class WebMarketDataOrchestrator
{
    /**
     * @var array<string, WebQuoteParser>
     */
    private array $parsers;

    public function __construct(
        private WebSourceRegistry $sourceRegistry,
        private WebQuoteFetcher $fetcher,
        private WebQuoteValidator $validator,
        private WebQuoteSelector $selector,
    ) {
        $this->parsers = collect([
            new TradegateQuoteParser,
            new OnvistaMarketsParser,
            new FinanzenMarketsParser,
            new QuotrixQuoteParser,
            new BoerseStuttgartQuoteParser,
            new JustEtfQuoteParser,
            new DeutscheBoerseLiveParser,
            new WienerBoerseParser,
            new EuronextLiveParser,
            new ArivaQuoteParser,
            new BoerseDeQuoteParser,
        ])->keyBy(fn (WebQuoteParser $parser): string => $parser->key())->all();
    }

    public function resolve(StockHolding $holding): QuoteSelectionResult
    {
        $instrument = InstrumentIdentity::fromHolding($holding);
        $candidates = $this->sourceRegistry->candidatesFor($holding);
        $validatedQuotes = [];
        $diagnostics = [];
        $errors = [];

        foreach ($candidates as $candidate) {
            $parser = $this->parsers[$candidate->parserKey] ?? null;

            if (! $parser) {
                $errors[] = "No parser registered for {$candidate->parserKey}.";

                continue;
            }

            $content = $this->fetcher->fetch($candidate->url);
            $diagnostic = new ParserDiagnostics($candidate->parserKey);
            $diagnostics[] = $diagnostic;

            if ($content === null) {
                $diagnostic->add("Could not fetch {$candidate->url}.");
                $this->markCandidateFailed($holding, $candidate);

                continue;
            }

            $parsedQuotes = $parser->parse($content, $candidate, $instrument, $diagnostic);

            if ($parsedQuotes === []) {
                $this->markCandidateFailed($holding, $candidate);
            }

            foreach ($parsedQuotes as $quote) {
                $validated = $this->validator->validate($quote, $instrument);
                $validatedQuotes[] = $validated;

                if ($validated->isSelectable()) {
                    $this->markCandidateSucceeded($holding, $candidate, $validated);
                }
            }
        }

        $result = $this->selector->select($validatedQuotes, $instrument, $candidates);
        $result->diagnostics = $diagnostics;
        $result->errors = $errors;

        $this->persistResult($holding, $result);

        return $result;
    }

    private function persistResult(StockHolding $holding, QuoteSelectionResult $result): void
    {
        $quoteModels = [];

        foreach ($result->quotes as $validatedQuote) {
            if ($validatedQuote === $result->selectedQuote || $validatedQuote->validationStatus === 'suspicious') {
                $quoteModels[spl_object_id($validatedQuote)] = $this->storeQuote($holding, $validatedQuote);
            }
        }

        if (! $result->selectedQuote) {
            $holding->update([
                'price_status' => $holding->latest_price === null ? 'unavailable_now' : 'stale',
            ]);

            return;
        }

        $selectedQuote = $quoteModels[spl_object_id($result->selectedQuote)] ?? $this->storeQuote($holding, $result->selectedQuote);
        $quote = $result->selectedQuote->quote;

        $holding->update([
            'currency' => $quote->currency ?? $holding->currency,
            'latest_price' => $quote->price,
            'latest_price_fetched_at' => $quote->fetchedAt,
            'latest_price_source' => $quote->sourceName,
            'latest_price_source_url' => $quote->sourceUrl,
            'latest_price_as_of' => $quote->asOf?->toDateTimeString(),
            'latest_quote_id' => $selectedQuote->id,
            'price_status' => $result->status,
            'latest_price_type' => $quote->priceType,
            'price_spread_pct' => $result->selectedQuote->spreadPct,
            'source_verified_at' => now(),
            'trading_times' => $this->tradingTimes($quote),
        ]);
    }

    private function storeQuote(StockHolding $holding, ValidatedQuote $validatedQuote): StockPriceQuote
    {
        $quote = $validatedQuote->quote;

        return $holding->priceQuotes()->create([
            'source_key' => $quote->sourceKey,
            'source_name' => $quote->sourceName,
            'source_url' => $quote->sourceUrl,
            'source_quality' => $quote->sourceQuality,
            'venue' => $quote->venue,
            'mic' => $quote->mic,
            'isin' => $quote->isin,
            'wkn' => $quote->wkn,
            'symbol' => $quote->symbol,
            'currency' => $quote->currency,
            'bid' => $quote->bid,
            'ask' => $quote->ask,
            'last' => $quote->last,
            'close' => $quote->close,
            'nav' => $quote->nav,
            'price' => $quote->price,
            'price_type' => $quote->priceType,
            'spread_abs' => $validatedQuote->spreadAbs,
            'spread_pct' => $validatedQuote->spreadPct,
            'as_of' => $quote->asOf,
            'fetched_at' => $quote->fetchedAt,
            'freshness_status' => $validatedQuote->freshnessStatus,
            'validation_status' => $validatedQuote->validationStatus,
            'validation_errors' => $validatedQuote->validationErrors,
            'raw_text_hash' => $quote->rawTextHash,
            'raw_payload' => (bool) config('market-data.store_raw_payloads') ? $quote->rawPayload : null,
        ]);
    }

    private function markCandidateSucceeded(StockHolding $holding, WebSourceCandidate $candidate, ValidatedQuote $quote): void
    {
        StockHoldingSourceCandidate::query()->updateOrCreate(
            [
                'stock_holding_id' => $holding->id,
                'source_key' => $candidate->sourceKey,
                'parser_key' => $candidate->parserKey,
            ],
            [
                'source_url' => $candidate->url,
                'venue' => $quote->quote->venue ?? $candidate->venue,
                'mic' => $quote->quote->mic ?? $candidate->mic,
                'confidence_score' => max($candidate->confidenceScore, 90),
                'last_success_at' => now(),
                'consecutive_failures' => 0,
                'active' => true,
                'verified' => true,
            ],
        );
    }

    private function markCandidateFailed(StockHolding $holding, WebSourceCandidate $candidate): void
    {
        $model = StockHoldingSourceCandidate::query()->firstOrNew([
            'stock_holding_id' => $holding->id,
            'source_key' => $candidate->sourceKey,
            'parser_key' => $candidate->parserKey,
        ]);

        $model->fill([
            'source_url' => $candidate->url,
            'venue' => $candidate->venue,
            'mic' => $candidate->mic,
            'confidence_score' => $candidate->confidenceScore,
            'last_failed_at' => now(),
            'consecutive_failures' => $model->exists ? $model->consecutive_failures + 1 : 1,
            'active' => $model->exists ? $model->consecutive_failures < 4 : true,
            'verified' => $model->verified ?? false,
        ])->save();
    }

    private function tradingTimes(ParsedQuote $quote): string
    {
        $timezone = $quote->asOf instanceof Carbon ? $quote->asOf->timezoneName : 'Europe/Berlin';

        return match ($quote->venue) {
            'Tradegate', 'gettex' => "Monday-Friday 08:00-22:00 {$timezone}",
            default => "Monday-Friday 09:00-17:30 {$timezone}",
        };
    }
}
