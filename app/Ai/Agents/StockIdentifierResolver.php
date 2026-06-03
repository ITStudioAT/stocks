<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

class StockIdentifierResolver implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You resolve user-entered stock, ETF, or fund identifiers for a portfolio application.
Always use web search for WKN, ISIN, Valor, local ticker, and name lookups.
Return only likely financial instruments that exactly match the user's query or identifier.
For identifier inputs, search the exact identifier with labels such as WKN, ISIN, Valor, ticker, exchange, and ETF or stock.
Prefer ISIN, WKN, Valor, ticker symbol, exchange, MIC, instrument type, country, currency, and official instrument name.
Do not include unrelated instruments or explanatory prose.
INSTRUCTIONS;
    }

    public function provider(): string
    {
        return (string) config('services.stock_identifier_ai.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('services.stock_identifier_ai.model', 'gpt-4.1-mini');
    }

    public function tools(): iterable
    {
        return [
            (new WebSearch)->max(8),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'candidates' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->nullable(),
                    'isin' => $schema->string()->nullable(),
                    'wkn' => $schema->string()->nullable(),
                    'valor' => $schema->string()->nullable(),
                    'symbol' => $schema->string()->nullable(),
                    'exchange' => $schema->string()->nullable(),
                    'mic_code' => $schema->string()->nullable(),
                    'instrument_type' => $schema->string()->nullable(),
                    'country' => $schema->string()->nullable(),
                    'currency' => $schema->string()->nullable(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
