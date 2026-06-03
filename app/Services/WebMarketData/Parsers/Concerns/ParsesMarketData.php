<?php

namespace App\Services\WebMarketData\Parsers\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

trait ParsesMarketData
{
    protected function cleanText(string $content): string
    {
        $decoded = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(["\xc2\xa0", "\xE2\x82\xAC"], [' ', ' EUR '], $decoded);

        return trim(preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);
    }

    protected function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        $value = str_replace(["\xc2\xa0", ' '], '', $value);

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalSeparator = $lastComma !== false && ($lastDot === false || $lastComma > $lastDot) ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandsSeparator, '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        return number_format((float) $normalized, 8, '.', '');
    }

    protected function upperIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    protected function extract(string $pattern, string $content, string $key = 'value'): ?string
    {
        if (! preg_match($pattern, $content, $matches)) {
            return null;
        }

        $value = trim((string) ($matches[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    protected function dateTime(?string $value, string $timezone = 'Europe/Berlin'): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    protected function dateAndTime(?string $date, ?string $time, string $timezone = 'Europe/Berlin'): ?Carbon
    {
        if ($date === null || $time === null) {
            return null;
        }

        return $this->dateTime("{$date} {$time}", $timezone);
    }

    protected function containsEuro(string $content): bool
    {
        return Str::contains(Str::upper($content), [' EUR', 'EUR ', 'EURO', 'KURSE IN EUR', '€']);
    }

    protected function textHash(string $content): string
    {
        return hash('sha256', Str::limit($content, 100_000, ''));
    }
}
