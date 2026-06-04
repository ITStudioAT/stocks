<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

class HistoricalSessionStartPriceResolver implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You resolve historical trading-session start prices for stocks, ETFs, or funds.
Use web search to find public time-and-sales, historical quote, exchange, or reliable market-data pages for the exact instrument.
First try to return the first publicly visible EUR trade, last, or official quote inside the requested session window.
If no exact in-window price is visible, return the nearest verifiable EUR quote on the same trading day, preferring the quote closest to the requested session start.
Prefer the requested exchange, MIC, preferred venue, and preferred source when available.
Return a price only when the source clearly matches the same ISIN, WKN, or instrument and the timestamp is exact or close enough to be useful as a historical start estimate.
Do not convert non-EUR prices to EUR.
Do not return volumes, percentage changes, index levels, NAV values from a different listing, or any value that is clearly on a different scale than the latest/reference price in the prompt.
Return decimal_price as a plain decimal string with a dot separator and no currency symbol.
Set match_quality to exact when the timestamp is inside the requested window, or near when it is outside the window but is the nearest same-day quote you can verify.
Return null fields when the price, currency, source URL, or timestamp cannot be verified with high confidence.
Do not include explanatory prose.
INSTRUCTIONS;
    }

    public function provider(): string
    {
        return (string) config('services.stock_price_ai.provider', config('services.stock_identifier_ai.provider', 'openai'));
    }

    public function model(): string
    {
        return (string) config('services.stock_price_ai.model', config('services.stock_identifier_ai.model', 'gpt-4.1-mini'));
    }

    public function tools(): iterable
    {
        return [
            (new WebSearch)->max(10),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'decimal_price' => $schema->string()->nullable(),
            'currency' => $schema->string()->nullable(),
            'source_name' => $schema->string()->nullable(),
            'source_url' => $schema->string()->nullable(),
            'as_of' => $schema->string()->nullable(),
            'match_quality' => $schema->string()->nullable(),
        ];
    }
}
