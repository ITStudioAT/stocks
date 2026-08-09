<?php

namespace App\Services;

use Illuminate\Support\Str;

class EodhdErrorSanitizer
{
    private const RedactedValue = '[redacted]';

    public function message(string $message, ?int $limit = null): string
    {
        $apiToken = config('services.eodhd.key');

        if (is_string($apiToken) && $apiToken !== '') {
            $message = str_replace($apiToken, self::RedactedValue, $message);
        }

        $message = preg_replace(
            '/((?:api(?:_|-|%5f|%255f)?token)(?:=|%3d|%253d))[^&\s"\'<>]+/i',
            '$1'.self::RedactedValue,
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/((?:"|\')?api(?:_|-|%5f|%255f)?token(?:"|\')?\s*(?::|=>)\s*(?:"|\')?)[^&\s"\',}]+/i',
            '$1'.self::RedactedValue,
            $message,
        ) ?? $message;

        return $limit === null ? $message : Str::limit($message, $limit, '');
    }

    public function payload(mixed $value, ?int $stringLimit = null): mixed
    {
        if (is_string($value)) {
            return $this->message($value, $stringLimit);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized[$key] = is_string($key) && $this->isApiTokenKey($key)
                ? self::RedactedValue
                : $this->payload($item, $stringLimit);
        }

        return $sanitized;
    }

    private function isApiTokenKey(string $key): bool
    {
        return preg_match('/^api(?:_|-|%5f|%255f)?token$/i', $key) === 1;
    }
}
