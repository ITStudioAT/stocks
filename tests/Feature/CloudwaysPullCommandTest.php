<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CloudwaysPullCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudways.deployment.base_url' => 'https://api.cloudways.test/api/v2',
            'services.cloudways.deployment.access_token' => 'test-access-token',
            'services.cloudways.deployment.server_id' => 123,
            'services.cloudways.deployment.app_id' => 456,
            'services.cloudways.deployment.branch' => 'main',
            'services.cloudways.deployment.deploy_path' => null,
            'services.cloudways.deployment.operation_timeout' => 60,
            'services.cloudways.deployment.poll_interval' => 1,
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_reports_missing_platform_pull_configuration(): void
    {
        config([
            'services.cloudways.deployment.access_token' => null,
            'services.cloudways.deployment.server_id' => null,
            'services.cloudways.deployment.app_id' => null,
        ]);

        $this->artisan('cloudways:pull --check')
            ->expectsOutputToContain('Cloudways platform Pull is not configured.')
            ->expectsOutputToContain('CLOUDWAYS_API_ACCESS_TOKEN')
            ->expectsOutputToContain('CLOUDWAYS_SERVER_ID')
            ->expectsOutputToContain('CLOUDWAYS_APP_ID')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_checks_configuration_without_calling_cloudways(): void
    {
        $this->artisan('cloudways:pull --check')
            ->expectsOutputToContain('Cloudways platform Pull configuration is present.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_requests_and_waits_for_cloudways_platform_pull(): void
    {
        Sleep::fake();
        Http::fake([
            'api.cloudways.test/api/v2/git/pull' => Http::response(['operation_id' => 789]),
            'api.cloudways.test/api/v2/git/history*' => Http::sequence()
                ->push([
                    'logs' => [[
                        'branch_name' => 'main',
                        'result' => 1,
                        'datetime' => '07, 08, 2026 - 09:00',
                        'description' => 'completed',
                    ]],
                ])
                ->push([
                    'logs' => [
                        [
                            'branch_name' => 'main',
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 10:00',
                            'description' => 'running',
                        ],
                        [
                            'branch_name' => 'main',
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 09:00',
                            'description' => 'completed',
                        ],
                    ],
                ])
                ->push([
                    'logs' => [
                        [
                            'branch_name' => 'main',
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 10:00',
                            'description' => 'completed successfully',
                        ],
                        [
                            'branch_name' => 'main',
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 09:00',
                            'description' => 'completed',
                        ],
                    ],
                ]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('Requesting Cloudways platform Pull')
            ->expectsOutputToContain('Cloudways: running')
            ->expectsOutputToContain('Cloudways platform Pull completed successfully.')
            ->assertSuccessful();

        Sleep::assertSleptTimes(1);
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.cloudways.test/api/v2/git/pull'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['server_id'] === 123
                && $request['app_id'] === 456
                && $request['branch_name'] === 'main';
        });
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.cloudways.test/api/v2/git/history?')
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['server_id'] === 123
                && $request['app_id'] === 456;
        });
    }

    public function test_it_fails_when_cloudways_reports_a_failed_pull(): void
    {
        Http::fake([
            'api.cloudways.test/api/v2/git/pull' => Http::response(['operation_id' => 789]),
            'api.cloudways.test/api/v2/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => [[
                    'branch_name' => 'main',
                    'result' => 0,
                    'datetime' => '07, 08, 2026 - 10:00',
                    'description' => 'Repository authentication failed',
                ]]]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('Cloudways: Repository authentication failed')
            ->expectsOutputToContain('Cloudways reported that the platform Pull failed.')
            ->assertFailed();
    }

    public function test_it_rejects_an_invalid_operation_response(): void
    {
        Http::fake([
            'api.cloudways.test/api/v2/git/pull' => Http::response(['status' => true]),
            'api.cloudways.test/api/v2/git/history*' => Http::response(['logs' => []]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('Cloudways did not return a valid deployment operation ID.')
            ->assertFailed();
    }
}
