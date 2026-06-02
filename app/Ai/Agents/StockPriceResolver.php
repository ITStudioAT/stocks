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
Return a price only when the source clearly refers to the same instrument. Prefer official exchange, issuer, broker market-data, or finance pages.
Return decimal_price as a plain decimal string with a dot separator and no currency symbol.
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
            (new WebSearch)->max(5),
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
        ];
    }
}
