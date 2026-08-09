<?php

namespace App\Services;

class TrustedProxyConfiguration
{
    /** @return array<int, string> */
    public static function validProxies(mixed $configuredProxies): array
    {
        if (! is_array($configuredProxies)) {
            return [];
        }

        return collect($configuredProxies)
            ->filter(fn (mixed $proxy): bool => self::isValidProxy($proxy))
            ->map(fn (string $proxy): string => trim($proxy))
            ->unique()
            ->values()
            ->all();
    }

    public static function hasInvalidEntries(mixed $configuredProxies): bool
    {
        if (! is_array($configuredProxies)) {
            return true;
        }

        return collect($configuredProxies)
            ->contains(fn (mixed $proxy): bool => ! self::isValidProxy($proxy));
    }

    private static function isValidProxy(mixed $proxy): bool
    {
        if (! is_string($proxy)) {
            return false;
        }

        $proxy = trim($proxy);

        if ($proxy === '' || in_array($proxy, ['*', '**', 'REMOTE_ADDR'], true)) {
            return false;
        }

        if (! str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }

        if (substr_count($proxy, '/') !== 1) {
            return false;
        }

        [$address, $prefix] = explode('/', $proxy, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || preg_match('/^\d+$/', $prefix) !== 1) {
            return false;
        }

        $maximumPrefix = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        $prefixLength = (int) $prefix;

        return $prefixLength >= 1 && $prefixLength <= $maximumPrefix;
    }
}
