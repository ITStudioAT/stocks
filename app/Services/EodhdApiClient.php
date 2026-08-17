<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EodhdApiClient
{
    public const AuthenticationErrorMessage = 'EODHD rejected the configured API token (HTTP 401). Update EODHD_API before retrying.';

    public function __construct(
        private EodhdApiUsage $apiUsage,
        private EodhdErrorSanitizer $errorSanitizer,
    ) {}

    public function get(string $path, array $query = [], int $usageUnits = 1): Response
    {
        $apiToken = config('services.eodhd.key');

        if (! is_string($apiToken) || trim($apiToken) === '') {
            throw new RuntimeException('EODHD API token is not configured.');
        }

        $this->apiUsage->recordCall($usageUnits);

        try {
            $response = Http::baseUrl((string) config('services.eodhd.base_url', 'https://eodhd.com/api'))
                ->acceptJson()
                ->connectTimeout((int) config('services.eodhd.connect_timeout', 5))
                ->timeout((int) config('services.eodhd.timeout', 20))
                ->get($path, [
                    ...$query,
                    'api_token' => $apiToken,
                ]);
        } catch (ConnectionException $exception) {
            throw new ConnectionException($this->errorSanitizer->message($exception->getMessage()));
        }

        if ($response->status() === 401) {
            throw new RuntimeException(self::AuthenticationErrorMessage, 401);
        }

        return $response;
    }

    public function configured(): bool
    {
        $apiToken = config('services.eodhd.key');

        return is_string($apiToken) && trim($apiToken) !== '';
    }
}
