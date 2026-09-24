<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PreviewControlClient
{
    private const ControlUrl = 'https://vorschau.gkstocks.at/preview/control';

    public function __construct(private PreviewControlSignature $signature, private PreviewIsolation $isolation) {}

    public function configured(): bool
    {
        return ! $this->isolation->active()
            && config('security.preview.control_url') === self::ControlUrl
            && preg_match('/^[a-f0-9]{64}$/D', (string) config('security.preview.control_key')) === 1;
    }

    /** @return array{configured: bool, enabled: bool|null} */
    public function status(): array
    {
        if (! $this->configured()) {
            return ['configured' => false, 'enabled' => null];
        }

        return ['configured' => true, 'enabled' => $this->exchange('GET', '')];
    }

    /** @return array{configured: bool, enabled: bool} */
    public function update(bool $enabled): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Preview control is not configured.');
        }

        $body = json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR);

        return ['configured' => true, 'enabled' => $this->exchange('POST', $body)];
    }

    private function exchange(string $method, string $body): bool
    {
        $request = Http::acceptJson()
            ->timeout(10)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders($this->signature->headers($method, 'preview/control', $body));
        $response = $method === 'GET'
            ? $request->get(self::ControlUrl)
            : $request->withBody($body, 'application/json')->post(self::ControlUrl);
        $enabled = $response->json('enabled');
        if (! $response->successful() || $response->header('X-Stocks-Preview') !== 'true'
            || ! is_bool($enabled)) {
            throw new RuntimeException('Preview control response could not be verified.');
        }

        return $enabled;
    }
}
