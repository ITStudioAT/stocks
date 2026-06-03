<?php

namespace App\Services\WebMarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WebQuoteFetcher
{
    public function fetch(string $url): ?string
    {
        return Cache::remember(
            $this->cacheKey($url),
            (int) config('market-data.cache_seconds', 45),
            fn (): ?string => $this->freshFetch($url),
        );
    }

    private function freshFetch(string $url): ?string
    {
        try {
            $response = $this->request()
                ->retry([250, 750], throw: false)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $contentType = strtolower((string) $response->header('content-type'));

        if ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'json') && ! str_contains($contentType, 'text') && ! str_contains($contentType, 'javascript')) {
            return null;
        }

        $body = trim($response->body());

        return $body === '' ? null : $body;
    }

    private function request(): PendingRequest
    {
        return Http::accept('text/html,application/xhtml+xml,application/json,text/plain')
            ->withHeaders([
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ])
            ->withUserAgent((string) config('market-data.user_agent'))
            ->connectTimeout((int) config('market-data.connect_timeout_seconds', 4))
            ->timeout((int) config('market-data.http_timeout_seconds', 8));
    }

    private function cacheKey(string $url): string
    {
        return 'web-market-data:'.sha1($url);
    }
}
