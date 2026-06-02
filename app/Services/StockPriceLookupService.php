<?php

namespace App\Services;

use App\Ai\Agents\StockPriceResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class StockPriceLookupService
{
    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string}  $instrument
     * @return array{price: ?string, currency: ?string, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string}
     */
    public function latestPrice(array $instrument): array
    {
        $fetchedAt = now();

        try {
            $response = StockPriceResolver::make()->prompt($this->prompt($instrument), timeout: 30);
        } catch (Throwable) {
            return $this->unavailableResult($fetchedAt);
        }

        $price = $this->decimalPrice(Arr::get($response, 'decimal_price'));

        if ($price === null) {
            return $this->unavailableResult(
                fetchedAt: $fetchedAt,
                source: $this->nullableString(Arr::get($response, 'source_name')) ?? 'AI SDK web search',
                sourceUrl: $this->nullableUrl(Arr::get($response, 'source_url')),
                asOf: $this->nullableString(Arr::get($response, 'as_of')),
            );
        }

        return [
            'price' => $price,
            'currency' => $this->nullableCurrency(Arr::get($response, 'currency')),
            'fetched_at' => $fetchedAt,
            'source' => $this->nullableString(Arr::get($response, 'source_name')) ?? 'AI SDK web search',
            'source_url' => $this->nullableUrl(Arr::get($response, 'source_url')),
            'as_of' => $this->nullableString(Arr::get($response, 'as_of')),
        ];
    }

    /**
     * @return array{price: null, currency: null, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string}
     */
    private function unavailableResult(
        Carbon $fetchedAt,
        string $source = 'AI SDK web search',
        ?string $sourceUrl = null,
        ?string $asOf = null,
    ): array {
        return [
            'price' => null,
            'currency' => null,
            'fetched_at' => $fetchedAt,
            'source' => $source,
            'source_url' => $sourceUrl,
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string}  $instrument
     */
    private function prompt(array $instrument): string
    {
        $details = collect([
            'Symbol' => $instrument['symbol'],
            'Name' => $instrument['name'] ?? null,
            'ISIN' => $instrument['isin'] ?? null,
            'WKN' => $instrument['wkn'] ?? null,
            'Exchange' => $instrument['exchange'] ?? null,
            'MIC' => $instrument['mic_code'] ?? null,
            'Currency' => $instrument['currency'] ?? null,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
            ->implode("\n");

        return <<<PROMPT
Find the latest available market price for this exact holding.

{$details}

Return null for decimal_price if the current/latest price cannot be verified for this exact instrument.
PROMPT;
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

        $normalized = str_replace(',', '.', $value);

        if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        return number_format((float) $normalized, 6, '.', '');
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

    private function nullableUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return Str::limit($value, 2048, '');
    }
}
