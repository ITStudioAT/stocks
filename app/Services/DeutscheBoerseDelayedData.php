<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class DeutscheBoerseDelayedData
{
    public const CatalogUrl = 'https://mfs.deutsche-boerse.com/api/DETR-posttrade';

    private const DownloadBase = 'https://mfs.deutsche-boerse.com/api/download/';

    private const Sources = [
        'DETR' => ['name' => 'Xetra', 'venues' => ['XETR', 'XETA', 'XETB', 'XETS'], 'close' => '17:36'],
        'DFRA' => ['name' => 'Frankfurt', 'venues' => ['XFRA', 'FRAA', 'FRAB'], 'close' => '22:00'],
        'DGAT' => ['name' => 'Tradegate', 'venues' => ['XGAT', 'XGRM'], 'close' => '22:00'],
    ];

    private const MaxFiles = 4;

    private const MaxCompressedBytes = 1048576;

    private const MaxExpandedBytes = 4194304;

    private const MaxTotalExpandedBytes = 8388608;

    private const MaxSeconds = 6;

    /** @param Collection<int, array<string, mixed>> $instruments @return array<string, mixed> */
    public function runBatch(Collection $instruments, int $batch = 0, ?string $before = null): array
    {
        if ($batch < 0 || $batch > 8) {
            throw new RuntimeException('Ungültiger Suchbereich.');
        }
        $source = ['DETR', 'DGAT', 'DFRA'][$batch % 3];
        $round = intdiv($batch, 3);
        $started = hrtime(true);
        try {
            $result = $this->run($instruments, $source, $round, $before, sampleOlder: true);
        } catch (RuntimeException $exception) {
            $result = [
                'status' => 'partial', 'checked_at' => now()->toIso8601String(),
                'source' => 'Deutsche Börse · '.self::Sources[$source]['name'].' Post-Trade',
                'source_code' => $source, 'search_before' => $before ?? now('UTC')->toIso8601String(),
                'round' => $round, 'next_round' => null, 'catalog_generated_at' => null,
                'newest_catalog_segment_at' => null, 'latest_observed_trade_at' => null, 'observed_age_minutes' => null,
                'files' => [], 'files_selected' => 0, 'records_checked' => 0, 'records_ignored' => 0,
                'matched_instruments' => 0, 'duration_ms' => (int) round((hrtime(true) - $started) / 1e6), 'source_failed' => true,
                'rate_limited' => $exception->getCode() === 429,
                'instruments' => $instruments->map(fn (array $item): array => [...$item, 'state' => 'not_found',
                    'price' => null, 'trade_at' => null, 'trade_venue' => null, 'trade_source' => self::Sources[$source]['name']])->values()->all(),
                'warnings' => [$exception->getCode() === 429 ? 'Deutsche Börse: Abruflimit (HTTP 429). Alle weiteren Abrufe dieser Anbieterfamilie werden gestoppt. Bitte später erneut versuchen.'
                    : self::Sources[$source]['name'].': Quelle nicht erreichbar, unerwartetes Format oder Zugriff in dieser Umgebung gesperrt. Andere Quellen werden weiter geprüft.'],
                'limits' => ['files' => self::MaxFiles, 'seconds' => self::MaxSeconds, 'instruments' => 50],
            ];
        }
        $result['batch'] = $batch;
        $result['next_batch'] = $batch < 8 ? $batch + 1 : null;
        $result['coverage'] = [[
            'source' => self::Sources[$source]['name'], 'source_code' => $source, 'round' => $round,
            'status' => ($result['source_failed'] ?? false) ? 'failed' : $result['status'],
            'files' => count($result['files']), 'selected' => $result['files_selected'],
            'from' => $result['files'] === [] ? null : min(array_column($result['files'], 'segment_at')),
            'to' => $result['files'] === [] ? null : max(array_column($result['files'], 'segment_at')),
        ]];

        return $result;
    }

    /**
     * @param  Collection<int, array{isin: string, name: string, symbol: ?string, exchange: ?string, mic_code: ?string, currency: ?string}>  $instruments
     * @return array<string, mixed>
     */
    public function run(Collection $instruments, string $source = 'DETR', int $round = 0, ?string $before = null, bool $sampleOlder = false): array
    {
        if (Cache::has('free-intraday.cooldown.deutsche-boerse')) {
            throw new RuntimeException('Deutsche Börse: provider cooldown after HTTP 429.', 429);
        }
        if (! isset(self::Sources[$source]) || $round < 0 || $round > 120) {
            throw new RuntimeException('Ungültiger Suchbereich.');
        }
        $searchBefore = $before === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($before)->utc();
        $started = hrtime(true) / 1e9;
        $deadline = $started + self::MaxSeconds;
        try {
            $catalog = json_decode($this->fetch('https://mfs.deutsche-boerse.com/api/'.$source.'-posttrade', 524288, $deadline), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Der öffentliche Dateikatalog konnte nicht sicher gelesen werden. Bitte später erneut versuchen.', $exception->getCode() === 429 ? 429 : 0);
        }
        if (! is_array($catalog) || ($catalog['SourcePrefix'] ?? null) !== $source.'-posttrade' || ! is_array($catalog['CurrentFiles'] ?? null)) {
            throw new RuntimeException('Der Anbieter liefert ein unerwartetes Katalogformat.');
        }

        [$files, $hasMore] = $this->selectFiles($catalog['CurrentFiles'], $source, $round, $searchBefore, $sampleOlder);
        $matches = [];
        $correctedIsins = [];
        $checkedFiles = [];
        $warnings = [];
        $expandedBytes = 0;
        $recordCount = 0;
        $ignoredCount = 0;
        $latestObserved = null;
        $rateLimited = false;
        $eligible = $instruments->filter(fn (array $item): bool => $source !== 'DETR' || $this->isXetra($item))->keyBy('isin');

        foreach ($files as $filename => $segmentAt) {
            try {
                $compressed = $this->download($filename, $source, $deadline);
                $text = @gzdecode($compressed, self::MaxExpandedBytes + 1);
                if ($text === false || strlen($text) > self::MaxExpandedBytes) {
                    throw new RuntimeException('Ungültige oder zu große komprimierte Datei.');
                }
                $expandedBytes += strlen($text);
                if ($expandedBytes > self::MaxTotalExpandedBytes) {
                    throw new RuntimeException('Das Datenlimit dieses Tests ist erreicht.');
                }
                $parsed = $this->parseTrades($text, $eligible, $deadline, $source);
                $recordCount += $parsed['records'];
                $ignoredCount += $parsed['ignored'];
                $correctedIsins += $parsed['corrected_isins'];
                $latestObserved = max($latestObserved, $parsed['latest_at']);
                foreach ($parsed['matches'] as $isin => $trade) {
                    if (! isset($matches[$isin]) || $trade['order_at'] > $matches[$isin]['order_at']) {
                        $matches[$isin] = $trade;
                    }
                }
                $checkedFiles[] = ['name' => $filename, 'segment_at' => $segmentAt, 'records' => $parsed['records']];
            } catch (RuntimeException $exception) {
                if ($exception->getCode() === 429) {
                    $warnings[] = 'Deutsche Börse: Abruflimit (HTTP 429). Alle weiteren Abrufe dieser Anbieterfamilie werden gestoppt. Bitte später erneut versuchen.';
                    $rateLimited = true;
                    break;
                }
                $warnings[] = $filename.': '.$exception->getMessage();
            }
        }

        $matches = array_diff_key($matches, $correctedIsins);
        if ($correctedIsins !== []) {
            $warnings[] = 'Bei Korrektur- oder Stornomeldungen wird für das betroffene Instrument in dieser Stichprobe kein Preis angezeigt.';
        }
        $rows = $instruments->map(function (array $item) use ($matches, $correctedIsins, $source): array {
            $trade = $matches[$item['isin']] ?? null;

            return [...$item, 'state' => $source === 'DETR' && ! $this->isXetra($item) ? 'not_xetra' : (isset($correctedIsins[$item['isin']]) ? 'correction' : ($trade === null ? 'not_found' : 'found')),
                'price' => $trade['price'] ?? null, 'trade_at' => $trade['trade_at'] ?? null,
                'trade_venue' => $trade['venue'] ?? null, 'trade_source' => self::Sources[$source]['name'],
                'trade_order_at' => $trade['order_at'] ?? null];
        })->values()->all();

        return [
            'status' => $warnings !== [] ? 'partial' : ($recordCount === 0 ? 'empty' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'source' => 'Deutsche Börse · '.self::Sources[$source]['name'].' Post-Trade',
            'source_code' => $source,
            'search_before' => $searchBefore->toIso8601String(),
            'next_round' => $hasMore && ! $rateLimited && $round < 120 ? $round + 1 : null,
            'rate_limited' => $rateLimited,
            'round' => $round,
            'source_url' => 'https://www.mds.deutsche-boerse.com/mds-en/real-time-data/Delayed-data',
            'delay_minutes' => 15,
            'catalog_generated_at' => $this->timestamp($catalog['GenerationDatetime'] ?? null),
            'newest_catalog_segment_at' => $files === [] ? null : reset($files),
            'latest_observed_trade_at' => $latestObserved,
            'observed_age_minutes' => $latestObserved === null ? null : max(0, (int) CarbonImmutable::parse($latestObserved)->diffInMinutes(now(), absolute: true)),
            'files' => $checkedFiles,
            'files_selected' => count($files),
            'records_checked' => $recordCount,
            'records_ignored' => $ignoredCount,
            'matched_instruments' => count($matches),
            'instruments' => $rows,
            'warnings' => $warnings,
            'duration_ms' => (int) round((hrtime(true) / 1e9 - $started) * 1000),
            'limits' => ['files' => self::MaxFiles, 'seconds' => self::MaxSeconds, 'instruments' => 50],
        ];
    }

    /** @param array<int, mixed> $names @return array{array<string, string>, bool} */
    private function selectFiles(array $names, string $source, int $round, CarbonImmutable $before, bool $sampleOlder): array
    {
        $files = [];
        foreach (array_slice($names, 0, 5000) as $name) {
            if (! is_string($name) || ! preg_match('/\A'.$source.'-posttrade-(\d{4}-\d{2}-\d{2}T\d{2}_\d{2})\.json\.gz\z/', $name, $parts)) {
                continue;
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d\TH_i', $parts[1], 'UTC');
                if ($date->format('Y-m-d\TH_i') !== $parts[1] || $date->greaterThan(now()) || $date->greaterThan($before)) {
                    continue;
                }
                $files[$name] = $date->toIso8601String();
            } catch (Throwable) {
                continue;
            }
        }
        krsort($files);
        $selected = array_slice($files, 0, 2, preserve_keys: true);
        $sessionFiles = array_filter($files, function (string $at) use ($source): bool {
            $date = CarbonImmutable::parse($at)->setTimezone('Europe/Berlin');
            $time = $date->format('H:i');

            return $date->isWeekday() && $time >= '09:00' && $time <= self::Sources[$source]['close'];
        });
        $selected += array_slice($sessionFiles, 0, 2, preserve_keys: true);
        $selected += array_slice($files, 0, self::MaxFiles, preserve_keys: true);

        $selected = array_slice($selected, 0, self::MaxFiles, preserve_keys: true);
        $remaining = array_diff_key($files, $selected);
        if ($round === 0) {
            return [$selected, $remaining !== []];
        }
        if ($sampleOlder) {
            $olderSession = array_diff_key($sessionFiles, $selected);
            $positions = $round === 1 ? [0, 60] : [180, 360];
            $sample = [];
            foreach ($positions as $position) {
                $sample += array_slice($olderSession, $position, 1, preserve_keys: true);
            }

            return [$sample, $round === 1 && count($olderSession) > 180];
        }

        return [array_slice($remaining, ($round - 1) * self::MaxFiles, self::MaxFiles, preserve_keys: true), count($remaining) > $round * self::MaxFiles];
    }

    private function download(string $filename, string $source, float $deadline): string
    {
        $response = $this->request(self::DownloadBase.$filename, self::MaxCompressedBytes, $deadline);
        if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            $location = $response->header('Location');
            $parts = parse_url($location);
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'storage.googleapis.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || (isset($parts['port']) && $parts['port'] !== 443)
                || ($parts['path'] ?? '') !== '/mv-cef-prod-europe-west3-private-min-by-min-files/'.$source.'/'.$filename) {
                throw new RuntimeException('Unerwartete Download-Weiterleitung wurde blockiert.');
            }
            $response = $this->request($location, self::MaxCompressedBytes, $deadline);
        }
        if (! $response->successful()) {
            if ($response->status() === 429) {
                throw new RuntimeException('Anbieter begrenzt Abrufe (HTTP 429).', 429);
            }
            throw new RuntimeException('Datei nicht verfügbar (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    private function fetch(string $url, int $limit, float $deadline): string
    {
        $response = $this->request($url, $limit, $deadline);
        if (! $response->successful()) {
            if ($response->status() === 429) {
                throw new RuntimeException('Anbieter begrenzt Abrufe (HTTP 429).', 429);
            }
            throw new RuntimeException('Anbieter nicht erreichbar.');
        }

        return $response->body();
    }

    private function request(string $url, int $limit, float $deadline): Response
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RuntimeException('Das Zeitlimit dieses Tests ist erreicht.');
        }
        try {
            $response = Http::connectTimeout(min(2, $remaining))->timeout(min(4, $remaining))
                ->withOptions([
                    'allow_redirects' => false, 'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new RuntimeException('Download zu groß.');
                        }
                    },
                    'progress' => static function (float $total, float $received) use ($limit, $deadline): void {
                        if ($received > $limit || hrtime(true) / 1e9 > $deadline) {
                            throw new RuntimeException('Downloadlimit erreicht.');
                        }
                    },
                ])->get($url);
            if (strlen($response->body()) > $limit) {
                throw new RuntimeException('Download zu groß.');
            }
            if ($response->status() === 429) {
                $retry = $response->header('Retry-After');
                try {
                    $seconds = ctype_digit($retry) ? (int) $retry : (int) now()->diffInSeconds(CarbonImmutable::parse($retry), false);
                } catch (Throwable) {
                    $seconds = 300;
                }
                Cache::put('free-intraday.cooldown.deutsche-boerse', true, now()->addSeconds(max(300, min(86400, $seconds))));
            }

            return $response;
        } catch (Throwable) {
            throw new RuntimeException('Download fehlgeschlagen oder Größen-/Zeitlimit erreicht.');
        }
    }

    /** @param Collection<string, array<string, mixed>> $eligible @return array{records: int, ignored: int, latest_at: ?string, corrected_isins: array<string, bool>, matches: array<string, array{price: string, trade_at: string, order_at: string, venue: string}>} */
    private function parseTrades(string $text, Collection $eligible, float $deadline, string $source): array
    {
        $result = ['records' => 0, 'ignored' => 0, 'latest_at' => null, 'matches' => [], 'corrected_isins' => []];
        $lines = explode("\n", trim($text));
        if (count($lines) > 20000) {
            throw new RuntimeException('Zu viele Ereignisse in einer Datei.');
        }
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (hrtime(true) / 1e9 > $deadline) {
                throw new RuntimeException('Das Zeitlimit dieses Tests ist erreicht.');
            }
            try {
                $trade = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException('Eine Datei enthält ungültiges zeilenweises JSON.');
            }
            if (! is_array($trade)) {
                $result['ignored']++;

                continue;
            }
            $at = $this->timestamp($trade['tradingDateAndTime'] ?? null);
            $isin = $trade['instrumentIdentificationCode'] ?? null;
            $price = $trade['price'] ?? null;
            if (($trade['messageId'] ?? null) === 'posttrade' && is_string($isin) && preg_match('/\A[A-Z]{2}[A-Z0-9]{9}[0-9]\z/', $isin)
                && ($trade['mmtModificationInd'] ?? '-') !== '-') {
                $result['corrected_isins'][$isin] = true;
            }
            if (($trade['messageId'] ?? null) !== 'posttrade' || ! is_string($isin) || ! preg_match('/\A[A-Z]{2}[A-Z0-9]{9}[0-9]\z/', $isin)
                || $at === null || CarbonImmutable::parse($at)->greaterThan(now())
                || ! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0
                || (string) ($trade['priceNotation'] ?? '') !== '1' || ($trade['mmtModificationInd'] ?? '-') !== '-') {
                $result['ignored']++;

                continue;
            }
            $result['records']++;
            $result['latest_at'] = max($result['latest_at'], $at);
            $item = $eligible->get($isin);
            $venue = $trade['venueOfExecution'] ?? null;
            if ($item === null || ($trade['priceCurrency'] ?? null) !== $item['currency'] || ! in_array($venue, self::Sources[$source]['venues'], true)) {
                continue;
            }
            preg_match('/\.(\d{1,9})Z\z/', $trade['tradingDateAndTime'], $fraction);
            $orderAt = substr($at, 0, 19).'.'.str_pad($fraction[1] ?? '', 9, '0').'Z';
            if (! isset($result['matches'][$isin]) || $orderAt > $result['matches'][$isin]['order_at']) {
                $result['matches'][$isin] = ['price' => (string) $price, 'trade_at' => $at, 'order_at' => $orderAt, 'venue' => $venue];
            }
        }

        return $result;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 48) {
            return null;
        }
        try {
            $value = str_replace(' UTC', 'Z', $value);
            $value = str_replace(' ', 'T', $value);
            if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z\z/', $value)) {
                return null;
            }
            $value = preg_replace('/(\.\d{6})\d+(Z)$/', '$1$2', $value);
            $date = CarbonImmutable::parse($value, 'UTC')->utc();
            if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                return null;
            }

            return $date->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $item */
    private function isXetra(array $item): bool
    {
        return strtoupper((string) $item['mic_code']) === 'XETR' || strtoupper((string) $item['exchange']) === 'XETRA';
    }
}
