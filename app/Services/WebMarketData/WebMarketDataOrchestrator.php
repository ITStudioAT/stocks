<?php

namespace App\Services\WebMarketData;

use App\Ai\Agents\StockPriceResolver;
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
use App\Services\WebMarketData\Parsers\BxSwissParser;
use App\Services\WebMarketData\Parsers\DeutscheBoerseLiveParser;
use App\Services\WebMarketData\Parsers\EuronextLiveParser;
use App\Services\WebMarketData\Parsers\FinanzenMarketsParser;
use App\Services\WebMarketData\Parsers\JustEtfQuoteParser;
use App\Services\WebMarketData\Parsers\OnvistaMarketsParser;
use App\Services\WebMarketData\Parsers\QuotrixQuoteParser;
use App\Services\WebMarketData\Parsers\TradegateQuoteParser;
use App\Services\WebMarketData\Parsers\WebQuoteParser;
use App\Services\WebMarketData\Parsers\WienerBoerseParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

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
            new BxSwissParser,
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

        $this->collectQuotes($holding, $instrument, $candidates, $validatedQuotes, $diagnostics, $errors);

        $result = $this->selector->select($validatedQuotes, $instrument, $candidates, applyCrossCheck: false);

        if ($this->shouldExtendSearch($result)) {
            $additionalCandidates = $this->additionalCandidates(
                $this->sourceRegistry->extendedCandidatesFor($holding),
                $candidates,
            );

            $this->collectQuotes($holding, $instrument, $additionalCandidates, $validatedQuotes, $diagnostics, $errors);

            $candidates = [
                ...$candidates,
                ...$additionalCandidates,
            ];

            $result = $this->selector->select($validatedQuotes, $instrument, $candidates);
        }

        if ($this->shouldAskAi($result)) {
            $aiQuote = $this->resolveWithAi($instrument, $result);

            if ($aiQuote) {
                $validatedQuotes[] = $this->validator->validate($aiQuote, $instrument);
                $candidates[] = new WebSourceCandidate(
                    sourceKey: 'ai_sdk_web_search',
                    sourceName: 'AI SDK web search',
                    url: $aiQuote->sourceUrl,
                    parserKey: 'ai_sdk_web_search',
                    quality: (string) config('market-data.sources.ai_sdk_web_search.quality', 'ai_assisted'),
                    priority: (int) config('market-data.sources.ai_sdk_web_search.priority', 500),
                );
                $result = $this->selector->select($validatedQuotes, $instrument, $candidates);
            }
        }

        $result->diagnostics = $diagnostics;
        $result->errors = $errors;

        $this->persistResult($holding, $result);

        return $result;
    }

    /**
     * @param  array<int, WebSourceCandidate>  $candidates
     * @param  array<int, ValidatedQuote>  $validatedQuotes
     * @param  array<int, ParserDiagnostics>  $diagnostics
     * @param  array<int, string>  $errors
     */
    private function collectQuotes(
        StockHolding $holding,
        InstrumentIdentity $instrument,
        array $candidates,
        array &$validatedQuotes,
        array &$diagnostics,
        array &$errors,
    ): void {
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
    }

    private function shouldExtendSearch(QuoteSelectionResult $result): bool
    {
        if (! $result->selectedQuote) {
            return true;
        }

        return in_array($result->status, ['stale', 'suspicious', 'unavailable'], true);
    }

    private function shouldAskAi(QuoteSelectionResult $result): bool
    {
        return (bool) config('market-data.ai_fallback_enabled', false)
            && $this->shouldExtendSearch($result);
    }

    private function resolveWithAi(InstrumentIdentity $instrument, QuoteSelectionResult $result): ?ParsedQuote
    {
        try {
            $response = StockPriceResolver::make()->prompt($this->aiPrompt($instrument, $result), timeout: 60);
        } catch (Throwable) {
            return null;
        }

        $price = $this->decimalPrice(Arr::get($response, 'decimal_price'));
        $currency = $this->nullableString(Arr::get($response, 'currency'));
        $sourceUrl = $this->nullableString(Arr::get($response, 'source_url'));
        $asOf = $this->parseAiTimestamp(Arr::get($response, 'as_of'));

        if ($price === null || $currency !== 'EUR' || $sourceUrl === null || $asOf === null) {
            return null;
        }

        return new ParsedQuote(
            sourceKey: 'ai_sdk_web_search',
            sourceName: $this->nullableString(Arr::get($response, 'source_name')) ?? 'AI SDK web search',
            sourceUrl: $sourceUrl,
            sourceQuality: (string) config('market-data.sources.ai_sdk_web_search.quality', 'ai_assisted'),
            venue: $instrument->exchange,
            mic: $instrument->mic,
            isin: $instrument->isin,
            wkn: $instrument->wkn,
            symbol: $instrument->symbol,
            currency: 'EUR',
            last: $price,
            price: $price,
            priceType: 'last',
            asOf: $asOf,
            fetchedAt: now(),
            freshnessStatus: 'delayed',
        );
    }

    private function aiPrompt(InstrumentIdentity $instrument, QuoteSelectionResult $result): string
    {
        $currentDateTime = now('Europe/Vienna')->toDateTimeString();
        $attemptedSources = collect($result->attemptedSources)
            ->map(fn (WebSourceCandidate $candidate): string => "- {$candidate->sourceName}: {$candidate->url}")
            ->implode("\n");
        $foundQuotes = collect($result->quotes)
            ->map(fn (ValidatedQuote $quote): string => sprintf(
                '- %s / %s / %s / %s / %s / %s',
                $quote->quote->sourceName,
                $quote->quote->venue ?? 'unknown venue',
                $quote->quote->price ?? 'no price',
                $quote->quote->currency ?? 'no currency',
                $quote->quote->asOf?->toDateTimeString() ?? 'no timestamp',
                implode('; ', $quote->validationErrors),
            ))
            ->implode("\n");

        return <<<PROMPT
Today is {$currentDateTime} Europe/Vienna.

Find a better current public EUR quote for this holding only if one is clearly visible with a quote timestamp:
- Symbol: {$instrument->symbol}
- Name: {$instrument->name}
- ISIN: {$instrument->isin}
- WKN: {$instrument->wkn}
- Exchange: {$instrument->exchange}
- MIC: {$instrument->mic}
- Currency: {$instrument->currency}

The deterministic refresh already checked these sources:
{$attemptedSources}

It found these weak quotes:
{$foundQuotes}

Prefer the holding exchange/MIC. If you cannot verify a current timestamped EUR quote from a reliable page, return null fields.
PROMPT;
    }

    private function decimalPrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function parseAiTimestamp(mixed $value): ?Carbon
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, WebSourceCandidate>  $candidates
     * @param  array<int, WebSourceCandidate>  $alreadyAttempted
     * @return array<int, WebSourceCandidate>
     */
    private function additionalCandidates(array $candidates, array $alreadyAttempted): array
    {
        $attemptedKeys = collect($alreadyAttempted)
            ->mapWithKeys(fn (WebSourceCandidate $candidate): array => [$this->candidateKey($candidate) => true]);

        return collect($candidates)
            ->reject(fn (WebSourceCandidate $candidate): bool => $attemptedKeys->has($this->candidateKey($candidate)))
            ->values()
            ->all();
    }

    private function candidateKey(WebSourceCandidate $candidate): string
    {
        return "{$candidate->parserKey}:{$candidate->url}";
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
            if ($holding->latest_price === null) {
                $holding->update([
                    'latest_price_source' => null,
                    'latest_price_source_url' => null,
                    'latest_price_as_of' => null,
                    'latest_quote_id' => null,
                    'price_status' => 'unavailable_now',
                    'latest_price_type' => null,
                    'price_spread_pct' => null,
                ]);

                return;
            }

            $holding->update([
                'price_status' => 'stale',
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
