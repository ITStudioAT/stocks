<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CloudwaysPullCommandTest extends TestCase
{
    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryStorage = sys_get_temp_dir().'/stocks-pull-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->temporaryStorage.'/framework', 0700, true);
        app()->useStoragePath($this->temporaryStorage);

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
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStorage);

        parent::tearDown();
    }

    public function test_it_restores_maintenance_during_rate_limit_wait_and_checks_history_before_retrying(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $events = [];
        Process::fake(function ($process) use (&$events) {
            $events[] = $process->command;

            if ($process->command[0] === 'bash') {
                File::put(storage_path('framework/cloudways-deploy-maintenance'), 'prepared');
                File::put(storage_path('framework/down'), '{}');
            } elseif (in_array('up', $process->command, true)) {
                File::delete(storage_path('framework/down'));
            }

            return Process::result();
        });
        Sleep::whenFakingSleep(function () use (&$events): void {
            $events[] = 'sleep';
            $this->assertFileDoesNotExist(storage_path('framework/down'));
        });
        Http::fake([
            '*/git/pull' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
                ->push(['status' => true]),
            '*/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => []])
                ->push(['logs' => [$this->completedDeployment()]]),
        ]);

        $this->artisan('cloudways:pull --prepare-deployment')
            ->expectsOutputToContain('waiting 7 seconds while the application is online')
            ->assertSuccessful();

        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        $this->assertSame('sleep', $events[3]);
        $this->assertSame(['GET', 'POST', 'GET', 'POST', 'GET'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
    }

    public function test_it_bounds_rate_limit_retries_and_waits_without_maintenance(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([
            '*/git/pull' => Http::response([], 429, ['Retry-After' => '1']),
            '*/git/history*' => Http::response(['logs' => []]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('after 3 attempts')
            ->assertFailed();

        $this->assertCount(3, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Sleep::assertSleptTimes(2);
    }

    public function test_it_respects_retry_date_and_reset_without_exceeding_the_wait_budget(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([
            '*/git/pull' => Http::response([], 429, [
                'Date' => now()->toRfc7231String(),
                'Retry-After' => now()->addSeconds(10)->toRfc7231String(),
                'X-RateLimit-Reset' => (string) now()->addSeconds(600)->timestamp,
            ]),
            '*/git/history*' => Http::response(['logs' => []]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('exceeds the automatic 300-second wait budget')
            ->assertFailed();

        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
    }

    public function test_it_adopts_a_new_history_entry_after_a_rate_limit_without_duplicate_pull(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([
            '*/git/pull' => Http::response([], 429, ['Retry-After' => '1']),
            '*/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => [$this->completedDeployment()]])
                ->push(['logs' => [$this->completedDeployment()]]),
        ]);

        $this->artisan('cloudways:pull')->assertSuccessful();

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_it_resumes_an_ambiguous_request_without_posting_again(): void
    {
        Http::fake([
            '*/git/pull' => Http::response([], 500),
            '*/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => [$this->completedDeployment()]]),
        ]);

        $this->artisan('cloudways:pull')->assertFailed();
        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('no new Pull will be requested')
            ->assertSuccessful();

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_history_rate_limits_do_not_repeat_an_accepted_pull(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([
            '*/git/pull' => Http::response(['status' => true]),
            '*/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push([], 429, ['Retry-After' => '2'])
                ->push(['logs' => [$this->completedDeployment()]]),
        ]);

        $this->artisan('cloudways:pull')->assertSuccessful();

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    /** @return array<string, mixed> */
    private function completedDeployment(): array
    {
        return ['branch_name' => 'main', 'datetime' => '2026-11-04 10:00:00', 'result' => 1, 'description' => 'completed'];
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

    public function test_it_checks_cloudways_git_history_access(): void
    {
        Http::fake([
            'api.cloudways.test/api/v2/git/history*' => Http::response(['logs' => []]),
        ]);

        $this->artisan('cloudways:pull --check')
            ->expectsOutputToContain('Cloudways Git Pull and History API access is working.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.cloudways.test/api/v2/git/history?')
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['server_id'] === 123
                && $request['app_id'] === 456;
        });
    }

    public function test_it_requests_and_waits_for_cloudways_platform_pull(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([
            'api.cloudways.test/api/v2/git/pull' => Http::response(['status' => true]),
            'api.cloudways.test/api/v2/git/history*' => Http::sequence()
                ->push([
                    'logs' => [[
                        'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                        'branch_name' => 'main',
                        'customer_id' => 5,
                        'path' => null,
                        'result' => 1,
                        'datetime' => '07, 08, 2026 - 09:00',
                        'description' => 'completed',
                    ]],
                ])
                ->push([
                    'logs' => [
                        [
                            'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                            'branch_name' => 'main',
                            'customer_id' => 5,
                            'path' => null,
                            'result' => 0,
                            'datetime' => '07, 08, 2026 - 10:00',
                            'description' => '',
                        ],
                        [
                            'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                            'branch_name' => 'main',
                            'customer_id' => 5,
                            'path' => null,
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 09:00',
                            'description' => 'completed',
                        ],
                    ],
                ])
                ->push([
                    'logs' => [
                        [
                            'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                            'branch_name' => 'main',
                            'customer_id' => 5,
                            'path' => null,
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 10:00',
                            'description' => 'completed successfully',
                        ],
                        [
                            'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                            'branch_name' => 'main',
                            'customer_id' => 5,
                            'path' => null,
                            'result' => 1,
                            'datetime' => '07, 08, 2026 - 09:00',
                            'description' => 'completed',
                        ],
                    ],
                ]),
        ]);

        $this->artisan('cloudways:pull')
            ->expectsOutputToContain('Requesting Cloudways platform Pull')
            ->expectsOutputToContain('Cloudways: Git deployment is pending.')
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
            'api.cloudways.test/api/v2/git/pull' => Http::response(['status' => true]),
            'api.cloudways.test/api/v2/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => [[
                    'git_url' => 'git@github.com:ITStudioAT/stocks.git',
                    'branch_name' => 'main',
                    'customer_id' => 5,
                    'path' => null,
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

    public function test_it_times_out_when_an_empty_history_entry_never_finishes(): void
    {
        config([
            'services.cloudways.deployment.operation_timeout' => 2,
            'services.cloudways.deployment.poll_interval' => 1,
        ]);
        Sleep::fake(syncWithCarbon: true);
        $pendingDeployment = [
            'git_url' => 'git@github.com:ITStudioAT/stocks.git',
            'branch_name' => 'main',
            'customer_id' => 5,
            'path' => null,
            'result' => 0,
            'datetime' => '07, 08, 2026 - 10:00',
            'description' => '',
        ];

        Http::fake([
            'api.cloudways.test/api/v2/git/pull' => Http::response(['status' => true]),
            'api.cloudways.test/api/v2/git/history*' => Http::sequence()
                ->push(['logs' => []])
                ->push(['logs' => [$pendingDeployment]])
                ->whenEmpty(Http::response(['logs' => [$pendingDeployment]])),
        ]);

        $this->artisan('cloudways:pull')
            ->doesntExpectOutputToContain('Cloudways reported that the platform Pull failed.')
            ->expectsOutputToContain('Cloudways platform Pull did not finish within 2 seconds.')
            ->assertFailed();

        Sleep::assertSleptTimes(2);
    }
}
