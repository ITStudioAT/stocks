<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeutscheBorseGraphqlClient
{
    private string $apiKey;

    private string $url;

    public function __construct()
    {
        $this->apiKey = (string) config('services.deutsche_borse.token');
        $this->url = (string) config('services.deutsche_borse.graphql_url');
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $this->ensureConfigured();

        $data = $this->request()
            ->post($this->url, array_filter([
                'query' => $query,
                'variables' => $variables,
            ], fn (mixed $value): bool => $value !== []))
            ->throw()
            ->json();

        if (! is_array($data)) {
            throw new RuntimeException('Deutsche Boerse returned an invalid GraphQL response.');
        }

        if (isset($data['errors'])) {
            throw new RuntimeException('Deutsche Boerse GraphQL request failed.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function contracts(string $product): array
    {
        return $this->query(
            <<<'GRAPHQL'
query Contracts($product: String!) {
    Contracts(filter: { Product: { eq: $product } }) {
        date
        data {
            ISIN
            Contract
            ExpirationDate
        }
    }
}
GRAPHQL,
            ['product' => $product],
        );
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'X-DBP-APIKEY' => $this->apiKey,
            ])
            ->timeout(15)
            ->retry(2, 200);
    }

    private function ensureConfigured(): void
    {
        if ($this->apiKey !== '' && $this->url !== '') {
            return;
        }

        throw new RuntimeException('Deutsche Boerse GraphQL access is not configured.');
    }
}
