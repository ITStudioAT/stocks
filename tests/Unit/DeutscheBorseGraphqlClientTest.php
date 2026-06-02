<?php

namespace Tests\Unit;

use App\Services\DeutscheBorseGraphqlClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DeutscheBorseGraphqlClientTest extends TestCase
{
    public function test_it_queries_deutsche_borse_graphql_with_api_key_header(): void
    {
        $this->configureDeutscheBorse();
        Http::preventStrayRequests();
        Http::fake([
            'api.deutsche-boerse.test/eurex-prod-graphql/' => Http::response([
                'data' => [
                    'Contracts' => [
                        'date' => '2026-06-02',
                        'data' => [
                            [
                                'ISIN' => 'DE000C7YLFE9',
                                'Contract' => 'FDAX SI 20260619 CS',
                                'ExpirationDate' => '2026-06-19',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = app(DeutscheBorseGraphqlClient::class)->contracts('FDAX');

        $this->assertSame('2026-06-02', $response['data']['Contracts']['date']);
        $this->assertSame('DE000C7YLFE9', $response['data']['Contracts']['data'][0]['ISIN']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.deutsche-boerse.test/eurex-prod-graphql/'
                && $request->hasHeader('X-DBP-APIKEY', 'test-key')
                && $request['variables']['product'] === 'FDAX';
        });
    }

    public function test_it_requires_configuration(): void
    {
        config([
            'services.deutsche_borse.token' => '',
            'services.deutsche_borse.graphql_url' => 'https://api.deutsche-boerse.test/eurex-prod-graphql/',
        ]);
        $this->app->forgetInstance(DeutscheBorseGraphqlClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deutsche Boerse GraphQL access is not configured.');

        app(DeutscheBorseGraphqlClient::class)->contracts('FDAX');
    }

    public function test_it_throws_for_graphql_errors(): void
    {
        $this->configureDeutscheBorse();
        Http::preventStrayRequests();
        Http::fake([
            'api.deutsche-boerse.test/eurex-prod-graphql/' => Http::response([
                'errors' => [
                    ['message' => 'Unauthorized'],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deutsche Boerse GraphQL request failed.');

        app(DeutscheBorseGraphqlClient::class)->contracts('FDAX');
    }

    private function configureDeutscheBorse(): void
    {
        config([
            'services.deutsche_borse.token' => 'test-key',
            'services.deutsche_borse.graphql_url' => 'https://api.deutsche-boerse.test/eurex-prod-graphql/',
        ]);
        $this->app->forgetInstance(DeutscheBorseGraphqlClient::class);
    }
}
