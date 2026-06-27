<?php

namespace App\Services;

use App\Models\EodhdExchange;
use App\Models\EodhdExchangeImportRun;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class EodhdExchangeDataImporter
{
    public function __construct(
        private EodhdApiClient $eodhdApiClient,
    ) {}

    public function createRun(): EodhdExchangeImportRun
    {
        return EodhdExchangeImportRun::query()->create([
            'id' => 'exchanges-'.Str::uuid()->toString(),
            'status' => 'queued',
            'started_at' => now(),
        ]);
    }

    public function runningRun(): ?EodhdExchangeImportRun
    {
        return EodhdExchangeImportRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    public function import(string $runId): void
    {
        $run = EodhdExchangeImportRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
        ]);

        try {
            $exchanges = $this->fetchExchangeList();
        } catch (RuntimeException $exception) {
            $this->failRun($run, $exception->getMessage());

            return;
        }

        $run->update([
            'total_count' => count($exchanges),
        ]);

        $detailsByCode = [];
        $detailErrorsByCode = [];

        foreach ($exchanges as $exchange) {
            if (! is_array($exchange)) {
                $this->advanceFailed($run, 'Invalid exchange row.');

                continue;
            }

            $code = $this->stringValue($exchange['Code'] ?? $exchange['code'] ?? null);

            if ($code === null) {
                $this->advanceFailed($run, 'Exchange without code.');

                continue;
            }

            $detailCode = $this->exchangeDetailCode($exchange);
            $run->update([
                'current' => $code,
            ]);

            if ($detailCode !== null && ! array_key_exists($detailCode, $detailsByCode) && ! array_key_exists($detailCode, $detailErrorsByCode)) {
                $detailResponse = $this->eodhdApiClient->get('v2/exchange-details/'.rawurlencode($detailCode));

                if ($detailResponse->failed()) {
                    $detailErrorsByCode[$detailCode] = "EODHD detail request failed with HTTP {$detailResponse->status()}.";
                } else {
                    try {
                        $details = $this->validDetailPayload($detailResponse, $detailCode);
                        $detailsByCode[$detailCode] = $details;
                    } catch (RuntimeException $exception) {
                        $detailErrorsByCode[$detailCode] = $exception->getMessage();
                    }
                }
            }

            $details = $detailCode === null ? null : ($detailsByCode[$detailCode] ?? null);
            $this->upsertExchange($exchange, $details, $detailCode);

            if ($detailCode !== null && array_key_exists($detailCode, $detailErrorsByCode)) {
                $this->advanceFailed($run, $detailErrorsByCode[$detailCode]);

                continue;
            }

            $this->advanceSuccess($run);
        }

        $run->refresh();
        $run->update([
            'status' => $run->failed_count > 0 ? ($run->success_count > 0 ? 'partial' : 'failed') : 'finished',
            'current' => null,
            'finished_at' => now(),
        ]);
    }

    public function fail(string $runId, string $message): void
    {
        $run = EodhdExchangeImportRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $this->failRun($run, $message);
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchExchangeList(): array
    {
        $response = $this->eodhdApiClient->get('exchanges-list/', [
            'fmt' => 'json',
        ]);

        if ($response->failed()) {
            throw new RuntimeException("EODHD request failed with HTTP {$response->status()}.");
        }

        $exchanges = $response->json();

        if (! is_array($exchanges)) {
            throw new RuntimeException('EODHD returned an invalid exchange response.');
        }

        return $exchanges;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchExchangeDetails(string $detailCode): array
    {
        $response = $this->eodhdApiClient->get('v2/exchange-details/'.rawurlencode($detailCode));

        return $this->validDetailPayload($response, $detailCode);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailPayloadFor(array $exchange): ?array
    {
        $detailCode = $this->exchangeDetailCode($exchange);

        if ($detailCode === null) {
            return null;
        }

        return $this->fetchExchangeDetails($detailCode);
    }

    public function exchangeDetailCode(array $exchange): ?string
    {
        $code = $this->stringValue($exchange['Code'] ?? $exchange['code'] ?? null);

        if ($code === null) {
            return null;
        }

        $mic = $this->stringValue(
            $exchange['OperatingMIC']
                ?? $exchange['OperatingMic']
                ?? $exchange['operating_mic']
                ?? $exchange['MIC']
                ?? $exchange['Mic']
                ?? $exchange['mic']
                ?? null,
        );

        return strtoupper($mic ?? $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshPayload(EodhdExchangeImportRun $run): array
    {
        return [
            'refresh_id' => $run->id,
            'status' => $run->status,
            'processed' => $run->processed_count,
            'total' => $run->total_count,
            'step' => "{$run->processed_count}/{$run->total_count}",
            'message' => $this->message($run),
            'current' => $run->current,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => is_array($run->error_summary) ? ($run->error_summary['message'] ?? null) : null,
            'success_count' => $run->success_count,
            'failed_count' => $run->failed_count,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exchangePayloads(): array
    {
        return EodhdExchange::query()
            ->orderBy('country')
            ->orderBy('name')
            ->orderBy('code')
            ->get()
            ->map(fn (EodhdExchange $exchange): array => [
                'id' => $exchange->id,
                'code' => $exchange->code,
                'detail_code' => $exchange->detail_code,
                'name' => $exchange->name,
                'country' => $exchange->country,
                'currency' => $exchange->currency,
                'timezone' => $exchange->timezone,
                'operating_mic' => $exchange->operating_mic,
                'trading_hours' => $exchange->trading_hours ?? [],
                'holidays' => $exchange->holidays ?? [],
                'synced_at' => $exchange->synced_at?->toIso8601String(),
                'updated_at' => $exchange->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function upsertExchange(array $exchange, ?array $details, ?string $detailCode): void
    {
        $code = $this->stringValue($exchange['Code'] ?? $exchange['code'] ?? null);

        if ($code === null) {
            return;
        }

        EodhdExchange::query()->updateOrCreate(
            ['code' => $code],
            [
                'detail_code' => $detailCode ?? strtoupper($code),
                'name' => $this->stringValue($exchange['Name'] ?? $exchange['name'] ?? null),
                'country' => $this->stringValue($exchange['Country'] ?? $exchange['country'] ?? null),
                'currency' => $this->stringValue($exchange['Currency'] ?? $exchange['currency'] ?? null),
                'timezone' => $this->stringValue($exchange['Timezone'] ?? $exchange['timezone'] ?? null),
                'operating_mic' => $this->stringValue(
                    $exchange['OperatingMIC']
                        ?? $exchange['OperatingMic']
                        ?? $exchange['operating_mic']
                        ?? null,
                ),
                'trading_hours' => is_array($details) ? ($details['TradingHours'] ?? null) : null,
                'holidays' => is_array($details) ? ($details['ExchangeHolidays'] ?? $details['Holidays'] ?? null) : null,
                'raw_exchange' => $exchange,
                'raw_details' => $details,
                'synced_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validDetailPayload(Response $response, string $detailCode): array
    {
        if ($response->failed()) {
            throw new RuntimeException("EODHD detail request for {$detailCode} failed with HTTP {$response->status()}.");
        }

        $details = $response->json();

        if (! is_array($details)) {
            throw new RuntimeException("EODHD returned an invalid exchange detail response for {$detailCode}.");
        }

        if (isset($details['data']) && is_array($details['data'])) {
            return $details['data'];
        }

        return $details;
    }

    private function advanceSuccess(EodhdExchangeImportRun $run): void
    {
        $run->increment('processed_count');
        $run->increment('success_count');
    }

    private function advanceFailed(EodhdExchangeImportRun $run, string $message): void
    {
        $run->increment('processed_count');
        $run->increment('failed_count');
        $run->update([
            'error_summary' => ['message' => Str::limit($message, 255, '')],
        ]);
    }

    private function failRun(EodhdExchangeImportRun $run, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'current' => null,
            'finished_at' => now(),
            'error_summary' => ['message' => Str::limit($message, 255, '')],
        ]);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function message(EodhdExchangeImportRun $run): string
    {
        if ($run->status === 'queued') {
            return 'Exchange reload queued.';
        }

        if ($run->status === 'running') {
            return "Reloading exchanges ({$run->processed_count}/{$run->total_count})...";
        }

        if ($run->status === 'failed') {
            return 'Exchange reload failed.';
        }

        if ($run->status === 'partial') {
            return 'Exchange reload finished with missing details.';
        }

        return 'Exchange reload finished.';
    }
}
