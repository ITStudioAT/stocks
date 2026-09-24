<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class CloudwaysApiClient
{
    /** @return array<int, string> */
    public function missingConfiguration(): array
    {
        $missingConfiguration = [];

        if ($this->configuredString('access_token') === null) {
            $missingConfiguration[] = 'CLOUDWAYS_API_ACCESS_TOKEN';
        }

        if ($this->configuredId('server_id') === null) {
            $missingConfiguration[] = 'CLOUDWAYS_SERVER_ID';
        }

        if ($this->configuredId('app_id') === null) {
            $missingConfiguration[] = 'CLOUDWAYS_APP_ID';
        }

        if ($this->configuredString('branch') === null) {
            $missingConfiguration[] = 'CLOUDWAYS_DEPLOY_BRANCH';
        }

        return $missingConfiguration;
    }

    public function startGitPull(): void
    {
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $serverId = $this->configuredId('server_id');
        $appId = $this->configuredId('app_id');
        $branch = $this->configuredString('branch');

        if ($this->missingConfiguration() !== [] || $serverId === null || $appId === null || $branch === null) {
            throw new RuntimeException('Cloudways deployment API configuration is incomplete.');
        }

        $payload = [
            'server_id' => $serverId,
            'app_id' => $appId,
            'branch_name' => $branch,
        ];
        $deployPath = $this->configuredString('deploy_path');

        if ($deployPath !== null) {
            $payload['deploy_path'] = $deployPath;
        }

        $this->request()
            ->asForm()
            ->post('/git/pull', $payload)
            ->throw();
    }

    /** @return array<int, array<string, mixed>> */
    public function gitDeploymentHistory(): array
    {
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $serverId = $this->configuredId('server_id');
        $appId = $this->configuredId('app_id');

        if ($serverId === null || $appId === null) {
            throw new RuntimeException('Cloudways deployment API configuration is incomplete.');
        }

        $response = $this->request()
            ->retry(
                [250, 750],
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            )
            ->get('/git/history', [
                'server_id' => $serverId,
                'app_id' => $appId,
            ])
            ->throw();
        $deployments = $response->json('logs');

        if (! is_array($deployments)) {
            throw new UnexpectedValueException('Cloudways returned an invalid Git deployment history.');
        }

        foreach ($deployments as $deployment) {
            if (! is_array($deployment)) {
                throw new UnexpectedValueException('Cloudways returned an invalid Git deployment history entry.');
            }
        }

        return array_values($deployments);
    }

    private function request(): PendingRequest
    {
        $accessToken = $this->configuredString('access_token');

        if ($accessToken === null) {
            throw new RuntimeException('Cloudways API access token is not configured.');
        }

        return Http::baseUrl(rtrim((string) config(
            'services.cloudways.deployment.base_url',
            'https://api.cloudways.com/api/v2',
        ), '/'))
            ->acceptJson()
            ->withToken($accessToken)
            ->connectTimeout(max(1, (int) config('services.cloudways.deployment.connect_timeout', 5)))
            ->timeout(max(1, (int) config('services.cloudways.deployment.timeout', 20)));
    }

    private function configuredId(string $key): ?int
    {
        $value = config("services.cloudways.deployment.{$key}");

        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function configuredString(string $key): ?string
    {
        $value = config("services.cloudways.deployment.{$key}");

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
