<?php

namespace App\Console\Commands;

use App\Services\CloudwaysApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('cloudways:pull
    {--check : Validate the Cloudways deployment API configuration without pulling}')]
#[Description('Pull origin through the Cloudways deployment API and wait for completion')]
class CloudwaysPullCommand extends Command
{
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

        try {
            if ($this->option('check')) {
                $cloudways->gitDeploymentHistory();
                $this->components->info('Cloudways Git Pull and History API access is working.');

                return self::SUCCESS;
            }

            $this->components->info('Requesting Cloudways platform Pull from the configured branch.');
            $deploymentHistoryBeforePull = $cloudways->gitDeploymentHistory();
            $this->ensureNoPullIsRunning($deploymentHistoryBeforePull);
            $cloudways->startGitPull();

            return $this->waitForDeployment($cloudways, $deploymentHistoryBeforePull);
        } catch (Throwable $exception) {
            $this->components->error("Cloudways platform Pull failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    /** @param array<int, array<string, mixed>> $deploymentHistoryBeforePull */
    private function waitForDeployment(CloudwaysApiClient $cloudways, array $deploymentHistoryBeforePull): int
    {
        $operationTimeout = max(1, (int) config('services.cloudways.deployment.operation_timeout', 600));
        $pollInterval = max(1, (int) config('services.cloudways.deployment.poll_interval', 3));
        $deadline = now()->addSeconds($operationTimeout);
        $lastMessage = null;
        $baselineIdentities = $this->identityCounts($this->matchingDeployments($deploymentHistoryBeforePull));
        $deploymentIdentity = null;

        while (true) {
            $deployments = $this->matchingDeployments($cloudways->gitDeploymentHistory());

            if ($deploymentIdentity === null) {
                $newDeploymentIdentities = $this->newDeploymentIdentities($deployments, $baselineIdentities);

                if (count($newDeploymentIdentities) > 1) {
                    throw new RuntimeException('Multiple new Cloudways Git deployments appeared; refusing an ambiguous handoff.');
                }

                $deploymentIdentity = $newDeploymentIdentities[0] ?? null;
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
