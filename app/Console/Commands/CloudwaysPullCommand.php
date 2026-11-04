<?php

namespace App\Console\Commands;

use App\Services\CloudwaysApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('cloudways:pull
    {--check : Validate the Cloudways deployment API configuration without pulling}
    {--prepare-deployment : Manage deployment maintenance around the platform Pull}
    {--source= : Expected release source commit}')]
#[Description('Pull origin through the Cloudways deployment API and wait for completion')]
class CloudwaysPullCommand extends Command
{
    private string $journalPath;

    /** @var array{baseline: array<string, int>, phase: string, source: string, retry_at: int, identity?: string} */
    private array $journal;

    public function handle(CloudwaysApiClient $cloudways): int
    {
        $missingConfiguration = $cloudways->missingConfiguration();

        if ($missingConfiguration !== []) {
            $this->components->error('Cloudways platform Pull is not configured.');
            $this->line('Set these values in the production environment:');

            foreach ($missingConfiguration as $environmentVariable) {
                $this->line(" - {$environmentVariable}");
            }

            return self::FAILURE;
        }

        $lock = null;

        try {
            if ($this->option('check')) {
                $cloudways->gitDeploymentHistory();
                $this->components->info('Cloudways Git Pull and History API access is working.');

                return self::SUCCESS;
            }

            $source = (string) $this->option('source');

            if ($source !== '' && ! preg_match('/^[a-f0-9]{40,64}$/', $source)) {
                throw new RuntimeException('Invalid deployment source commit.');
            }

            $key = hash('sha256', json_encode([
                config('services.cloudways.deployment.base_url'),
                config('services.cloudways.deployment.server_id'),
                config('services.cloudways.deployment.app_id'),
                config('services.cloudways.deployment.branch'),
                config('services.cloudways.deployment.deploy_path'),
            ], JSON_THROW_ON_ERROR));
            $this->journalPath = storage_path("framework/cloudways-pull-{$key}.json");
            $lock = fopen($this->journalPath.'.lock', 'c');

            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another Cloudways platform Pull command is already running.');
            }

            if (is_file($this->journalPath)) {
                $this->journal = json_decode(File::get($this->journalPath), true, flags: JSON_THROW_ON_ERROR);

                if ($this->journal['source'] !== $source) {
                    throw new RuntimeException('An unresolved Pull belongs to a different source. Inspect Cloudways before continuing.');
                }

                if ($this->journal['phase'] !== 'rate_limited') {
                    $this->components->warn('Resuming history checks for the previous Pull; no new Pull will be requested.');

                    return $this->waitForDeployment($cloudways, $this->journal['baseline']);
                }
            } else {
                $history = $cloudways->gitDeploymentHistory();
                $this->ensureNoPullIsRunning($history);
                $this->journal = [
                    'baseline' => $this->identityCounts($this->matchingDeployments($history)),
                    'phase' => 'ready',
                    'source' => $source,
                    'retry_at' => 0,
                ];
            }

            return $this->requestPull($cloudways);
        } catch (Throwable $exception) {
            $message = $exception instanceof RequestException
                ? 'HTTP '.$exception->response->status().' from Cloudways.'
                : $exception->getMessage();
            $this->components->error("Cloudways platform Pull failed: {$message}");

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function saveJournal(): void
    {
        File::replace($this->journalPath, json_encode($this->journal, JSON_THROW_ON_ERROR), 0600);
    }

    private function requestPull(CloudwaysApiClient $cloudways): int
    {
        $waited = 0;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->journal['phase'] === 'rate_limited') {
                $delay = max(0, $this->journal['retry_at'] - now()->timestamp);

                if ($waited + $delay > 300) {
                    throw new RuntimeException('Cloudways cooldown exceeds the automatic 300-second wait budget. Retry gitdeploy after '.gmdate('Y-m-d H:i:s', $this->journal['retry_at']).' UTC.');
                }

                $this->line("Cloudways rate limit: waiting {$delay} seconds while the application is online.");
                $waited += $delay;
                $this->pause($delay);
                $history = $cloudways->gitDeploymentHistory();
                $identities = $this->newDeploymentIdentities($this->matchingDeployments($history), $this->journal['baseline']);

                if ($identities !== []) {
                    $this->journal['phase'] = 'accepted';
                    $this->saveJournal();

                    return $this->waitForDeployment($cloudways, $this->journal['baseline']);
                }

                $this->ensureNoPullIsRunning($history);
            }

            if ($this->option('prepare-deployment')) {
                $this->runDeploymentProcess(['bash', 'scripts/deploy_cloudways.sh', '--prepare']);
            }

            $this->journal['phase'] = 'requesting';
            $this->saveJournal();
            $this->components->info("Requesting Cloudways platform Pull (attempt {$attempt}/3).");

            try {
                $cloudways->startGitPull();
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 429) {
                    if (in_array($exception->response->status(), [400, 401, 403, 404, 405, 422], true)) {
                        $this->restoreRejectedPull();
                        File::delete($this->journalPath);
                    }

                    throw $exception;
                }

                $delay = $this->retryDelay($exception->response);
                $this->journal['phase'] = 'rate_limited';
                $this->journal['retry_at'] = now()->timestamp + $delay;
                $this->saveJournal();
                $this->restoreRejectedPull();
                $this->components->warn('Cloudways rejected the Pull with HTTP 429. Next attempt no earlier than '.gmdate('Y-m-d H:i:s', $this->journal['retry_at']).' UTC.');

                continue;
            }

            $this->journal['phase'] = 'accepted';
            $this->saveJournal();

            return $this->waitForDeployment($cloudways, $this->journal['baseline']);
        }

        throw new RuntimeException('Cloudways still rate limits Git Pull after 3 attempts. The application is online; no further Pull was requested.');
    }

    /** @param array<int, string> $command */
    private function runDeploymentProcess(array $command): void
    {
        $result = Process::path(base_path())
            ->timeout(360)
            ->env(['STOCKS_CLOUDWAYS_TERMINAL_PULL' => 'true'])
            ->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed()) {
            throw new RuntimeException('Deployment maintenance operation failed; inspect the application state before continuing.');
        }
    }

    private function restoreRejectedPull(): void
    {
        if (! $this->option('prepare-deployment')) {
            return;
        }

        $marker = storage_path('framework/cloudways-deploy-maintenance');

        if (! is_file($marker) || trim(File::get($marker)) !== 'prepared') {
            throw new RuntimeException('Maintenance ownership changed; inspect before retrying the rejected Pull.');
        }

        $this->runDeploymentProcess([PHP_BINARY, 'artisan', 'up', '--no-interaction']);
        File::delete($marker);
        $this->runDeploymentProcess([PHP_BINARY, 'scripts/deployment-status.php', 'idle']);
    }

    private function retryDelay(Response $response): int
    {
        $now = now()->timestamp;
        $reference = strtotime($response->header('Date')) ?: $now;
        $retryAfter = trim($response->header('Retry-After'));
        $delays = [];

        if (ctype_digit($retryAfter)) {
            $delays[] = (int) $retryAfter;
        } elseif ($retryAfter !== '' && ($retryAt = strtotime($retryAfter)) !== false) {
            $delays[] = $retryAt - $reference;
        }

        $reset = trim($response->header('X-RateLimit-Reset'));

        if (ctype_digit($reset)) {
            $delays[] = (int) $reset - $reference;
        }

        return max(1, $delays === [] ? 60 : max($delays));
    }

    private function pause(int $seconds): void
    {
        while ($seconds > 0) {
            $interval = min(30, $seconds);
            Sleep::for($interval)->seconds();
            $seconds -= $interval;
        }
    }

    /** @param array<string, int> $baselineIdentities */
    private function waitForDeployment(CloudwaysApiClient $cloudways, array $baselineIdentities): int
    {
        $operationTimeout = max(1, (int) config('services.cloudways.deployment.operation_timeout', 600));
        $pollInterval = max(1, (int) config('services.cloudways.deployment.poll_interval', 3));
        $deadline = now()->addSeconds($operationTimeout);
        $lastMessage = null;
        $deploymentIdentity = $this->journal['identity'] ?? null;

        while (true) {
            try {
                $deployments = $this->matchingDeployments($cloudways->gitDeploymentHistory());
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 429) {
                    throw $exception;
                }

                $delay = $this->retryDelay($exception->response);

                if (now()->addSeconds($delay)->greaterThanOrEqualTo($deadline)) {
                    throw new RuntimeException('Cloudways history is rate limited beyond the polling deadline. Resume gitdeploy to check this same Pull; do not request another.');
                }

                $this->line("Cloudways history rate limit: waiting {$delay} seconds; no new Pull will be requested.");
                $this->pause($delay);

                continue;
            }

            if ($deploymentIdentity === null) {
                $newDeploymentIdentities = $this->newDeploymentIdentities($deployments, $baselineIdentities);

                if (count($newDeploymentIdentities) > 1) {
                    throw new RuntimeException('Multiple new Cloudways Git deployments appeared; refusing an ambiguous handoff.');
                }

                $deploymentIdentity = $newDeploymentIdentities[0] ?? null;

                if ($deploymentIdentity !== null) {
                    $this->journal['identity'] = $deploymentIdentity;
                    $this->saveJournal();
                }
            }

            $deployment = $deploymentIdentity === null
                ? null
                : $this->deploymentWithIdentity($deployments, $deploymentIdentity);
            $status = $deployment === null
                ? ['completion' => 0, 'message' => 'Waiting for the Git deployment to appear in Cloudways history.']
                : $this->deploymentStatus($deployment);

            if ($status['message'] !== '' && $status['message'] !== $lastMessage) {
                $this->line("Cloudways: {$status['message']}");
                $lastMessage = $status['message'];
            }

            if ($status['completion'] === 1) {
                File::delete($this->journalPath);
                $this->components->info('Cloudways platform Pull completed successfully.');

                return self::SUCCESS;
            }

            if ($status['completion'] === -1) {
                $this->components->error('Cloudways reported that the platform Pull failed.');

                return self::FAILURE;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                $this->components->error("Cloudways platform Pull did not finish within {$operationTimeout} seconds.");

                return self::FAILURE;
            }

            Sleep::for($pollInterval)->seconds();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $deployments
     * @return array<string, int>
     */
    private function identityCounts(array $deployments): array
    {
        $identities = [];

        foreach ($deployments as $deployment) {
            $identity = $this->deploymentIdentity($deployment);
            $identities[$identity] = ($identities[$identity] ?? 0) + 1;
        }

        return $identities;
    }

    /**
     * @param  array<int, array<string, mixed>>  $deployments
     * @param  array<string, int>  $baselineIdentities
     * @return array<int, string>
     */
    private function newDeploymentIdentities(array $deployments, array $baselineIdentities): array
    {
        $currentIdentities = $this->identityCounts($deployments);
        $newDeploymentIdentities = [];

        foreach ($currentIdentities as $identity => $count) {
            if ($count > ($baselineIdentities[$identity] ?? 0)) {
                $newDeploymentIdentities[] = $identity;
            }
        }

        return $newDeploymentIdentities;
    }

    /** @param array<string, mixed> $deployment */
    private function deploymentIdentity(array $deployment): string
    {
        return hash('sha256', json_encode([
            'git_url' => (string) data_get($deployment, 'git_url', ''),
            'branch_name' => (string) data_get($deployment, 'branch_name', ''),
            'customer_id' => (string) data_get($deployment, 'customer_id', ''),
            'path' => $this->normalizedDeployPath(data_get($deployment, 'path')),
            'datetime' => Str::squish((string) data_get($deployment, 'datetime', '')),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array<string, mixed>>  $deployments
     * @return array<int, array<string, mixed>>
     */
    private function matchingDeployments(array $deployments): array
    {
        $configuredBranch = (string) config('services.cloudways.deployment.branch', 'main');
        $configuredPath = $this->normalizedDeployPath(config('services.cloudways.deployment.deploy_path'));

        return array_values(array_filter(
            $deployments,
            fn (array $deployment): bool => (string) data_get($deployment, 'branch_name') === $configuredBranch
                && $this->normalizedDeployPath(data_get($deployment, 'path')) === $configuredPath,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $deployments
     * @return null|array<string, mixed>
     */
    private function deploymentWithIdentity(array $deployments, string $identity): ?array
    {
        foreach ($deployments as $deployment) {
            if ($this->deploymentIdentity($deployment) === $identity) {
                return $deployment;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $deployments */
    private function ensureNoPullIsRunning(array $deployments): void
    {
        $latestDeployment = $this->matchingDeployments($deployments)[0] ?? null;

        if ($latestDeployment === null || $this->deploymentStatus($latestDeployment)['completion'] !== 0) {
            return;
        }

        throw new RuntimeException('A Cloudways Git deployment is already running for this branch and path.');
    }

    private function normalizedDeployPath(mixed $path): string
    {
        if (! is_string($path)) {
            return '';
        }

        return trim($path, " \t\n\r\0\x0B/");
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array{completion: int, message: string}
     */
    private function deploymentStatus(array $deployment): array
    {
        $message = Str::of(implode(' ', array_filter([
            data_get($deployment, 'status'),
            data_get($deployment, 'description'),
        ], 'is_string')))
            ->squish()
            ->limit(300)
            ->toString();
        $normalizedMessage = Str::lower($message);

        if (Str::contains($normalizedMessage, ['fail', 'error', 'denied', 'fatal', 'abort', 'unable'])) {
            return ['completion' => -1, 'message' => $message];
        }

        if (Str::contains($normalizedMessage, [
            'running',
            'progress',
            'pending',
            'initiated',
            'pulling',
            'deploying',
            'processing',
            'queued',
            'starting',
            'started',
        ])) {
            return ['completion' => 0, 'message' => $message];
        }

        if (Str::contains($normalizedMessage, [
            'success',
            'complete',
            'deployed',
            'finished',
            'up to date',
            'up-to-date',
        ])) {
            return ['completion' => 1, 'message' => $message];
        }

        $result = data_get($deployment, 'result');

        if (is_numeric($result) && (int) $result < 0) {
            return ['completion' => -1, 'message' => $message];
        }

        if (is_numeric($result) && (int) $result === 0) {
            return [
                'completion' => $message === '' ? 0 : -1,
                'message' => $message === '' ? 'Git deployment is pending.' : $message,
            ];
        }

        if (is_numeric($result) && (int) $result === 1) {
            return ['completion' => 1, 'message' => $message];
        }

        return ['completion' => 0, 'message' => $message];
    }
}
