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
Use web search when the input is a WKN, local ticker, or ambiguous name.
Return only likely financial instruments that exactly match the user's query.
Prefer ISIN, WKN, ticker symbol, exchange, and official instrument name.
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
            (new WebSearch)->max(5),
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
                    'symbol' => $schema->string()->nullable(),
                    'exchange' => $schema->string()->nullable(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
