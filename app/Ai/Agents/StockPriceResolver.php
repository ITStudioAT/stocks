<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

class StockPriceResolver implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You resolve the latest publicly visible market price for a stock, ETF, or fund holding.
Use web search to find the exact instrument from the provided symbol, name, ISIN, WKN, exchange, MIC, and currency.
Check sources in the exact order provided by the user prompt. Do not change the order between requests.
Return a price only when the source clearly refers to the same instrument and quotes the price in EUR.
If one source has no EUR quote, continue with the next source in the provided order.
Use the latest visible trade or last price. If the market is closed, use the most recent official close or latest available price with its date/time.
Do not convert non-EUR prices to EUR. Return null values only after every ordered source has been checked and only non-EUR or unverifiable prices are available.
Return decimal_price as a plain decimal string with a dot separator and no currency symbol.
Return trading_times when the exact exchange or venue trading schedule is visible or can be verified for the matched instrument. Use the exchange's local timezone.
Return null values when the price is unavailable or confidence is low. Do not include explanatory prose.
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
            'trading_times' => $schema->string()->nullable(),
        ];
    }
}
