<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\StockHolding;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class XetraIntradayCollector
{
    public const CatalogUrl = 'https://mfs.deutsche-boerse.com/api/DETR-posttrade';

    public const ReportKey = 'free_intraday.xetra.coverage.v1';

    private const Root = 'free-intraday/xetra/v1';

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    /** @return array<string, mixed> */
    public function collect(bool $advance = false, bool $refreshCatalog = false, bool $coordinated = false): array
    {
        if (app(MarketDataMode::class)->paused()) {
            throw new MarketDataSourceBusy('Market-data deployment upgrade is in progress.');
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        if ($this->result !== null && ! $advance) {
            return $this->result;
        }

        $holdings = StockHolding::query()->orderBy('id')->get()
            ->filter(fn (StockHolding $holding): bool => IntradayMarketIdentity::isXetra($holding->toArray()));
        if ($holdings->isEmpty()) {
            return $this->result = ['checked_at' => now()->toIso8601String(), 'instruments' => [], 'series' => [], 'days' => [],
                'pending_files' => 0, 'complete' => true, 'warnings' => []];
        }
        if (MarketPriceStore::enabled() && ! $coordinated && (! $holdings->contains(fn (StockHolding $holding): bool => app(FreeTradingCalendar::class)->shouldCollect($holding))
            || ! app(MarketDataCoordinator::class)->reserve('xetra'))) {
            return $this->unavailable($holdings, 'Coordinated five-minute interval or market closure; no provider request.');
        }
        $lock = Cache::lock('free-intraday.xetra.collector.v1', 150);
        if (! $lock->get()) {
            return $this->result = $this->unavailable($holdings, 'Another Xetra collector is running.');
        }

        try {
            return $this->result = $this->collectLocked($holdings, $refreshCatalog);
        } finally {
            $lock->release();
        }
    }

    /** @param Collection<int, StockHolding> $holdings @return array<string, mixed> */
    private function collectLocked(Collection $holdings, bool $refreshCatalog = false): array
    {
        $identities = $holdings->map(fn (StockHolding $holding): array => $holding->only(['id', 'isin', 'symbol', 'exchange', 'mic_code', 'currency']))->values()->all();
        $universe = hash('sha256', json_encode($identities, JSON_THROW_ON_ERROR));
        $directory = self::Root.'/'.$universe;
        Storage::disk('local')->makeDirectory($directory);
        $statePath = Storage::disk('local')->path($directory.'/state.json');
        $state = is_file($statePath) ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR) : ['files' => []];
        $error = null;

        try {
            if (Cache::has('free-intraday.cooldown.deutsche-boerse')) {
                throw new RuntimeException('Xetra provider cooldown after HTTP 429/403; no paid fallback.');
            }
            $loadCatalog = function (): array {
                $this->throttle();
                $response = Http::connectTimeout(4)->timeout(10)->withOptions(['allow_redirects' => false])->get(self::CatalogUrl);
                $this->checkStatus($response->status(), $response->header('Retry-After'));
                if (strlen($response->body()) > 1048576) {
                    throw new RuntimeException('Xetra catalogue exceeds the size limit.');
                }
                $catalog = $response->json();
                if (! is_array($catalog) || ($catalog['SourcePrefix'] ?? null) !== 'DETR-posttrade' || ! is_array($catalog['CurrentFiles'] ?? null)) {
                    throw new RuntimeException('Invalid Xetra file catalogue.');
                }

                return $catalog;
            };
            $catalog = $refreshCatalog ? $loadCatalog()
                : Cache::remember('free-intraday.xetra.catalog.v1', now()->addSeconds(30), $loadCatalog);
            $state['catalog'] = $this->catalogFiles($catalog['CurrentFiles']);
            $state['catalog_checked_at'] = now()->toIso8601String();
            $state['catalog_generated_at'] = $catalog['GenerationDatetime'] ?? null;
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
        }

        $plans = [...($state['open_plans'] ?? []), ...$this->plans($state['catalog'] ?? [], $state['files'])];
        ksort($plans);
        $state['open_plans'] = $plans;
        $pending = array_unique(array_merge(...array_values(array_map(fn (array $plan): array => $plan['files'], $plans))));
        $deadline = microtime(true) + (int) config('services.free_intraday.batch_seconds', 20);
        $processed = 0;
        foreach ($pending as $filename) {
            $entry = $state['files'][$filename] ?? [];
            $path = Storage::disk('local')->path($directory.'/'.$filename.'.json');
            if (($entry['status'] ?? null) === 'complete' && is_file($path)) {
                continue;
            }
            if (! isset($state['catalog'][$filename]) && ! is_file(Storage::disk('local')->path(self::Root.'/downloads/'.$filename))) {
                continue;
            }
            if ($error !== null || microtime(true) >= $deadline || $processed >= (int) config('services.free_intraday.batch_files', 32)) {
                break;
            }
            if (($entry['retry_at'] ?? 0) > now()->timestamp) {
                continue;
            }
            $processed++;
            $raw = null;
            try {
                $raw = $this->download($filename);
                $parsed = $this->parse($raw, $holdings->keyBy('isin'));
                File::replace($path, json_encode($parsed, JSON_THROW_ON_ERROR));
                $state['files'][$filename] = ['status' => 'complete', 'sha256' => hash_file('sha256', $raw),
                    'checked_at' => now()->toIso8601String(), 'records' => count($parsed['trades']), 'attempts' => ($entry['attempts'] ?? 0) + 1];
            } catch (Throwable $exception) {
                if (is_string($raw) && is_file($raw)) {
                    File::delete($raw);
                }
                $attempts = ($entry['attempts'] ?? 0) + 1;
                $state['files'][$filename] = ['status' => 'failed', 'error' => $this->safeError($exception), 'attempts' => $attempts,
                    'retry_at' => now()->addSeconds(max(MarketPriceStore::enabled() ? 300 : 0, min(3600, 15 * (2 ** min(8, $attempts)))))->timestamp];
                if (in_array($exception->getCode(), [403, 429], true)) {
                    $error = $state['files'][$filename]['error'];
                }
            }
            File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        }
        File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));

        $result = $this->assemble($holdings, $state, $plans, $directory, $error);
        $state['open_plans'] = array_filter($plans, fn (array $plan, string $date): bool => ! $result['days'][$date]['complete'], ARRAY_FILTER_USE_BOTH);
        File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        $report = $result;
        unset($report['series']);
        AppConfig::query()->updateOrCreate(['key' => self::ReportKey], ['value' => $report]);

        return $result;
    }

    /** @param array<int, mixed> $names @return array<string, string> */
    private function catalogFiles(array $names): array
    {
        $files = [];
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/\ADETR-posttrade-(?:(daily)-(\d{4}-\d{2}-\d{2})|(\d{4}-\d{2}-\d{2})T(\d{2})_(\d{2}))\.json\.gz\z/', $name, $parts)) {
                continue;
            }
            $date = $parts[2] ?: $parts[3];
            try {
                if (Carbon::createFromFormat('!Y-m-d', $date, 'UTC')->format('Y-m-d') !== $date || $date > now('UTC')->toDateString()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $files[$name] = $date;
        }

        return $files;
    }

    /** @param array<string, string> $catalog @param array<string, mixed> $completed @return array<string, array<string, mixed>> */
    private function plans(array $catalog, array $completed): array
    {
        $today = now('Europe/Berlin')->startOfDay();
        $previous = $today->copy()->subDay();
        while ($previous->isWeekend()) {
            $previous->subDay();
        }
        $dates = array_unique([$previous->toDateString(), $today->toDateString(), ...array_values($catalog)]);
        $plans = [];
        foreach ($dates as $date) {
            if ($date < $previous->toDateString() || $date > $today->toDateString() || Carbon::parse($date)->isWeekend()) {
                continue;
            }
            $open = Carbon::parse($date.' '.config('services.free_intraday.session_start', '08:00'), 'Europe/Berlin')->utc();
            $close = Carbon::parse($date.' '.config('services.free_intraday.session_end', '22:01'), 'Europe/Berlin')->utc();
            $until = $close->copy()->min(now('UTC')->subMinutes(16)->startOfMinute());
            $daily = 'DETR-posttrade-daily-'.$date.'.json.gz';
            $files = [];
            $absent = [];
            if ($date < $today->toDateString() && isset($catalog[$daily])) {
                $files[] = $daily;
            } else {
                for ($minute = $open->copy(); $minute->lessThan($until); $minute->addMinute()) {
                    $file = 'DETR-posttrade-'.$minute->format('Y-m-d\TH_i').'.json.gz';
                    if (isset($catalog[$file]) || isset($completed[$file])) {
                        $files[] = $file;
                    } else {
                        $absent[] = $file;
                    }
                }
                rsort($files);
            }
            $plans[$date] = ['from' => $open->toIso8601String(), 'until' => $until->max($open)->toIso8601String(),
                'files' => $files, 'absent' => $absent, 'daily' => $files === [$daily]];
        }

        return $plans;
    }

    private function download(string $filename): string
    {
        $directory = self::Root.'/downloads';
        Storage::disk('local')->makeDirectory($directory);
        $path = Storage::disk('local')->path($directory.'/'.$filename);
        if (is_file($path)) {
            return $path;
        }
        $temporary = $path.'.part';
        $limit = str_contains($filename, '-daily-') ? 134217728 : 2097152;
        $url = 'https://mfs.deutsche-boerse.com/api/download/'.$filename;
        try {
            $this->throttle();
            foreach (range(0, 1) as $redirect) {
                $response = Http::connectTimeout(4)->timeout(45)->withOptions(['allow_redirects' => false, 'sink' => $temporary,
                    'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new RuntimeException('Xetra compressed file exceeds the size limit.');
                        }
                    }, 'progress' => static function (float $total, float $received) use ($limit): void {
                        if ($received > $limit) {
                            throw new RuntimeException('Xetra compressed download exceeds the size limit.');
                        }
                    }])->get($url);
                if ($response->redirect() && $redirect === 0) {
                    $url = (string) $response->header('Location');
                    $parts = parse_url($url);
                    $folder = str_contains($filename, '-daily-') ? 'daily/' : '';
                    if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'storage.googleapis.com'
                        || ($parts['path'] ?? null) !== '/mv-cef-prod-europe-west3-private-min-by-min-files/DETR/'.$folder.$filename
                        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                        throw new RuntimeException('Unexpected Xetra download redirect blocked.');
                    }

                    continue;
                }
                $this->checkStatus($response->status(), $response->header('Retry-After'));
                if (! is_file($temporary) || filesize($temporary) === 0) {
                    File::put($temporary, $response->body());
                }
                if (filesize($temporary) > $limit) {
                    throw new RuntimeException('Xetra file exceeds the size limit.');
                }
                File::move($temporary, $path);

                return $path;
            }
            throw new RuntimeException('Xetra redirect limit exceeded.');
        } finally {
            if (is_file($temporary)) {
                File::delete($temporary);
            }
        }
    }

    /** @return Generator<int, string> */
    private function lines(string $path): Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open Xetra gzip file.');
        }
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $ended = false;
        $input = '';
        $buffer = '';
        $bytes = 0;
        try {
            while (true) {
                if ($input === '') {
                    $input = fread($stream, 8192);
                    if ($input === false) {
                        throw new RuntimeException('Cannot read Xetra gzip file.');
                    }
                    if ($input === '') {
                        break;
                    }
                }
                if ($ended) {
                    $context = inflate_init(ZLIB_ENCODING_GZIP);
                    $ended = false;
                }
                $before = inflate_get_read_len($context);
                $decoded = @inflate_add($context, $input, ZLIB_SYNC_FLUSH);
                $consumed = inflate_get_read_len($context) - $before;
                if ($decoded === false || $consumed <= 0 || ! in_array(inflate_get_status($context), [ZLIB_OK, ZLIB_STREAM_END], true)) {
                    throw new RuntimeException('Corrupt Xetra gzip file.');
                }
                $input = substr($input, $consumed);
                $ended = inflate_get_status($context) === ZLIB_STREAM_END;
                $bytes += strlen($decoded);
                if ($bytes > 1073741824) {
                    throw new RuntimeException('Xetra expanded file exceeds the size limit.');
                }
                $buffer .= $decoded;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    if ($newline > 65536) {
                        throw new RuntimeException('Xetra record exceeds the size limit.');
                    }
                    yield substr($buffer, 0, $newline + 1);
                    $buffer = substr($buffer, $newline + 1);
                }
                if (strlen($buffer) > 65536) {
                    throw new RuntimeException('Xetra record exceeds the size limit.');
                }
            }
            if (! $ended) {
                throw new RuntimeException('Corrupt or truncated Xetra gzip file.');
            }
            if ($buffer !== '') {
                yield $buffer;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param Collection<string, StockHolding> $holdings @return array{trades: array<string, array<string, mixed>>, corrections: array<int, string>} */
    private function parse(string $path, Collection $holdings): array
    {
        $trades = [];
        $corrections = [];

        foreach ($this->lines($path) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $trade = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $holding = $holdings->get($trade['instrumentIdentificationCode'] ?? '');
            if ($holding === null || ($trade['messageId'] ?? null) !== 'posttrade') {
                continue;
            }
            if (($trade['priceCurrency'] ?? null) !== $holding->currency || ! in_array($trade['venueOfExecution'] ?? null, IntradayMarketIdentity::XetraMics, true)) {
                throw new RuntimeException("Xetra identity/currency/venue mismatch for {$holding->symbol}.");
            }
            if (($trade['mmtModificationInd'] ?? '-') !== '-') {
                $corrections[$holding->isin] = $holding->isin;

                continue;
            }
            $event = $trade['tradingDateAndTime'] ?? '';
            if (! is_string($event) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z\z/', $event)) {
                throw new RuntimeException("Invalid Xetra event time for {$holding->symbol}.");
            }
            $at = Carbon::parse(preg_replace('/(\.\d{6})\d+Z$/', '$1Z', $event))->utc();
            if ($at->format('Y-m-d\TH:i:s') !== substr($event, 0, 19)) {
                throw new RuntimeException("Invalid Xetra calendar date for {$holding->symbol}.");
            }
            preg_match('/\.(\d{1,9})Z$/', $event, $fraction);
            $event = substr($event, 0, 19).'.'.str_pad($fraction[1] ?? '', 9, '0').'Z';
            $price = $trade['price'] ?? null;
            if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0 || (string) ($trade['priceNotation'] ?? '') !== '1' || $at->greaterThan(now())) {
                throw new RuntimeException("Invalid Xetra trade for {$holding->symbol}.");
            }
            $id = (string) ($trade['transactionIdentificationCode'] ?? hash('sha256', $holding->isin.'|'.$event.'|'.$price.'|'.($trade['quantity'] ?? '')));
            $trades[$holding->isin.'|'.$id] = ['isin' => $holding->isin, 'id' => $id, 'event_at' => $event,
                'timestamp' => $at->timestamp, 'close' => number_format((float) $price, 8, '.', ''),
                'quantity' => $trade['quantity'] ?? null, 'currency' => $holding->currency, 'mic' => $trade['venueOfExecution']];
        }

        return ['trades' => $trades, 'corrections' => array_values($corrections)];
    }

    /** @param Collection<int, StockHolding> $holdings @param array<string, mixed> $state @param array<string, array<string, mixed>> $plans @return array<string, mixed> */
    private function assemble(Collection $holdings, array $state, array $plans, string $directory, ?string $error): array
    {
        $days = [];
        $trades = [];
        $corrections = [];
        foreach ($plans as $date => $plan) {
            $missing = $plan['absent'];
            $failures = [];
            foreach ($plan['files'] as $filename) {
                $path = Storage::disk('local')->path($directory.'/'.$filename.'.json');
                $entry = $state['files'][$filename] ?? [];
                if (($entry['status'] ?? null) !== 'complete' || ! is_file($path)) {
                    $missing[] = $filename;
                    if (isset($entry['error'])) {
                        $failures[$filename] = $entry['error'];
                    }

                    continue;
                }
                $parsed = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
                foreach ($parsed['trades'] as $id => $trade) {
                    if (isset($trades[$id]) && $trades[$id] !== $trade) {
                        $corrections[] = $trade['isin'];
                    }
                    $trades[$id] ??= $trade;
                }
                $corrections = array_unique([...$corrections, ...$parsed['corrections']]);
            }
            $days[$date] = ['from' => $plan['from'], 'until' => $plan['until'], 'daily_file' => $plan['daily'],
                'expected_files' => count($plan['files']) + count($plan['absent']), 'processed_files' => count($plan['files']) - count($missing) + count($plan['absent']),
                'missing_files' => array_values($missing), 'file_errors' => $failures, 'complete' => $missing === [] && $error === null];
        }
        uasort($trades, fn (array $left, array $right): int => strcmp($left['event_at'], $right['event_at']));
        $series = [];
        foreach ($trades as $trade) {
            $isin = $trade['isin'];
            if (in_array($isin, $corrections, true)) {
                continue;
            }
            $second = $trade['timestamp'];
            $events = [...($series[$isin][$second]['events'] ?? []), $trade];
            $date = Carbon::createFromTimestampUTC($second)->setTimezone('Europe/Berlin')->toDateString();
            $series[$isin][$second] = ['timestamp' => $second, 'datetime' => gmdate('Y-m-d H:i:s', $second),
                'open' => null, 'high' => null, 'low' => null, 'close' => $trade['close'], 'volume' => null,
                'interval' => 'xtrade', 'currency' => $trade['currency'], 'data_kind' => 'observed_trade',
                'source_key' => 'xetra_posttrade', 'source_name' => 'Deutsche Börse Xetra · 15 min delayed trades',
                'source_url' => self::CatalogUrl, 'venue' => 'Xetra', 'mic' => $trade['mic'], 'isin' => $isin,
                'event_at' => $trade['event_at'], 'events' => $events, 'trade_count' => count($events),
                'coverage_complete' => $days[$date]['complete'] ?? false,
                'coverage_from' => $days[$date]['from'] ?? null, 'coverage_until' => $days[$date]['until'] ?? null, 'delay_minutes' => 15];
        }
        $instruments = [];
        foreach ($holdings as $holding) {
            $records = array_values($series[$holding->isin] ?? []);
            $latest = $records === [] ? null : $records[array_key_last($records)];
            $instrumentDays = $days;
            foreach ($instrumentDays as $date => &$day) {
                $dayRecords = array_values(array_filter($records, fn (array $record): bool => Carbon::createFromTimestampUTC($record['timestamp'])->setTimezone('Europe/Berlin')->toDateString() === $date));
                $day['trade_count'] = array_sum(array_column($dayRecords, 'trade_count'));
                $day['last_trade_at'] = $dayRecords === [] ? null : $dayRecords[array_key_last($dayRecords)]['event_at'];
                $day['missing_file_count'] = count($day['missing_files']);
                $day['file_error_count'] = count($day['file_errors']);
                unset($day['missing_files'], $day['file_errors']);
            }
            unset($day);
            $complete = $days !== [] && collect($days)->every(fn (array $day): bool => $day['complete']) && ! in_array($holding->isin, $corrections, true);
            $lastDay = $instrumentDays === [] ? null : $instrumentDays[array_key_last($instrumentDays)];
            $instruments[] = [...$holding->only(['id', 'isin', 'symbol', 'currency', 'exchange', 'mic_code']),
                'status' => in_array($holding->isin, $corrections, true) ? 'unresolved_corrections' : ($complete ? (($lastDay['trade_count'] ?? 0) === 0 ? 'no_trades' : 'complete') : 'incomplete'),
                'coverage_complete' => $complete, 'trade_count' => array_sum(array_column($records, 'trade_count')),
                'price' => $latest['close'] ?? null, 'trade_at' => $latest === null ? null : Carbon::createFromTimestampUTC($latest['timestamp'])->toIso8601String(), 'trade_venue' => $latest['mic'] ?? null,
                'quote_age_minutes' => $latest === null ? null : (int) Carbon::createFromTimestampUTC($latest['timestamp'])->diffInMinutes(now(), absolute: true),
                'trade_source' => 'Xetra', 'fetched_at' => now()->toIso8601String(), 'days' => $instrumentDays,
                'error' => in_array($holding->isin, $corrections, true) ? 'Corrections require reconciliation; affected observations quarantined.' : $error];
        }

        return ['checked_at' => now()->toIso8601String(), 'catalog_checked_at' => $state['catalog_checked_at'] ?? null,
            'catalog_generated_at' => $state['catalog_generated_at'] ?? null, 'days' => $days, 'instruments' => $instruments,
            'series' => array_map('array_values', $series), 'unresolved_corrections' => array_values($corrections),
            'complete' => $instruments !== [] && collect($instruments)->every(fn (array $item): bool => $item['coverage_complete']),
            'pending_files' => collect($days)->sum(fn (array $day): int => count($day['missing_files'])),
            'retryable_files' => collect($days)->sum(function (array $day) use ($state): int {
                return count(array_filter($day['missing_files'], function (string $file) use ($state): bool {
                    preg_match('/\d{4}-\d{2}-\d{2}/', $file, $date);

                    return isset($state['catalog'][$file]) || is_file(Storage::disk('local')->path(self::Root.'/downloads/'.$file))
                        || ($date[0] ?? '') >= now('Europe/Berlin')->subWeekdays(1)->toDateString();
                }));
            }),
            'warnings' => $error === null ? [] : [$error]];
    }

    private function checkStatus(int $status, ?string $retryAfter): void
    {
        if (in_array($status, [403, 429], true)) {
            $seconds = is_string($retryAfter) && ctype_digit($retryAfter) ? (int) $retryAfter : 300;
            Cache::put('free-intraday.cooldown.deutsche-boerse', true, now()->addSeconds(max(300, min(86400, $seconds))));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Xetra HTTP {$status}; file remains pending, no paid fallback.", $status);
        }
    }

    private function throttle(): void
    {
        $interval = (int) config('services.free_intraday.request_interval_ms', 2500) / 1000;
        if ($interval <= 0) {
            return;
        }
        $key = 'free-intraday.xetra.next-request.v1';
        $delay = min($interval, max(0, (float) Cache::get($key, 0) - microtime(true)));
        if ($delay > 0) {
            usleep((int) ($delay * 1000000));
        }
        Cache::put($key, microtime(true) + $interval, now()->addMinutes(5));
    }

    private function safeError(Throwable $exception): string
    {
        return get_class($exception) === RuntimeException::class ? mb_substr($exception->getMessage(), 0, 250) : 'Xetra network, compression or JSON error; file remains pending.';
    }

    /** @param Collection<int, StockHolding> $holdings @return array<string, mixed> */
    private function unavailable(Collection $holdings, string $message): array
    {
        return ['checked_at' => now()->toIso8601String(), 'instruments' => $holdings->map(fn (StockHolding $holding): array => [
            ...$holding->only(['id', 'isin', 'symbol', 'currency']), 'price' => null, 'coverage_complete' => false, 'status' => 'incomplete', 'error' => $message, 'collector_busy' => true,
        ])->values()->all(), 'series' => [], 'days' => [], 'pending_files' => 1, 'complete' => false, 'collector_busy' => true, 'warnings' => [$message]];
    }
}
