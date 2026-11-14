<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class XetraInstrumentCatalog
{
    public const Url = 'https://www.cashmarket.deutsche-boerse.com/resource/blob/1528/b52ea43a2edac92e8283d40645d1c076/data/t7-xetr-allTradableInstruments.csv';

    private const CacheKey = 'free-instruments.xetra.v1';

    private const MaxBytes = 8388608;

    /** @return array{status: string, as_of: ?string, instruments: array<int, array<string, mixed>>} */
    public function search(string $query): array
    {
        $catalog = $this->catalog();
        $needle = Str::upper(trim($query));
        $catalog['instruments'] = collect($catalog['instruments'])
            ->filter(fn (array $instrument): bool => collect(['isin', 'wkn', 'symbol', 'name'])
                ->contains(fn (string $field): bool => str_contains(Str::upper((string) $instrument[$field]), $needle)))
            ->sortBy(fn (array $instrument): int => in_array($needle, [$instrument['isin'], $instrument['wkn'], $instrument['symbol']], true) ? 0 : 1)
            ->take(25)->values()->all();

        return $catalog;
    }

    /** @return array{status: string, as_of: ?string, instruments: array<int, array<string, mixed>>} */
    private function catalog(): array
    {
        $cached = Cache::get(self::CacheKey);
        if (is_array($cached)) {
            return ['status' => 'available', ...$cached];
        }
        if (Cache::has(self::CacheKey.'.cooldown')) {
            return $this->fallback();
        }

        try {
            $catalog = Cache::lock(self::CacheKey.'.lock', 30)->get(function (): array {
                $cached = Cache::get(self::CacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
                $catalog = $this->parse($this->download());
                Cache::put(self::CacheKey, $catalog, now()->addHours(6));
                Cache::put(self::CacheKey.'.stale', $catalog, now()->addDays(7));

                return $catalog;
            });

            return is_array($catalog) ? ['status' => 'available', ...$catalog] : $this->fallback();
        } catch (Throwable) {
            Cache::put(self::CacheKey.'.cooldown', true, now()->addMinutes(5));

            return $this->fallback();
        }
    }

    private function download(): string
    {
        $url = self::Url;
        $deadline = microtime(true) + 15;
        for ($redirects = 0; $redirects <= 2; $redirects++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Instrument catalogue download timed out.');
            }
            $response = Http::connectTimeout(min(4, $remaining))->timeout($remaining)
                ->withUserAgent('GKStocks instrument reference data')
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MaxBytes) {
                            throw new RuntimeException('Instrument catalogue exceeds the download limit.');
                        }
                    },
                    'progress' => static function (float $total, float $received) use ($deadline): void {
                        if ($received > self::MaxBytes || microtime(true) > $deadline) {
                            throw new RuntimeException('Instrument catalogue exceeds the download limit.');
                        }
                    },
                ])->get($url);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $url = (string) $response->header('Location');
                if (! preg_match('~\Ahttps://www\.cashmarket\.deutsche-boerse\.com/resource/blob/1528/[a-f0-9]{32}/data/t7-xetr-allTradableInstruments\.csv\z~', $url)) {
                    throw new RuntimeException('Unexpected instrument catalogue redirect.');
                }

                continue;
            }
            if (! $response->successful() || strlen($response->body()) > self::MaxBytes) {
                throw new RuntimeException('Instrument catalogue is currently unavailable.');
            }

            return $response->body();
        }

        throw new RuntimeException('Too many instrument catalogue redirects.');
    }

    /** @return array{status: string, as_of: ?string, instruments: array<int, array<string, mixed>>} */
    private function fallback(): array
    {
        $stale = Cache::get(self::CacheKey.'.stale');

        return is_array($stale) && Carbon::parse($stale['as_of'], 'Europe/Berlin')->greaterThanOrEqualTo(now('Europe/Berlin')->subDays(7)->startOfDay())
            ? ['status' => 'stale', ...$stale]
            : ['status' => 'unavailable', 'as_of' => null, 'instruments' => []];
    }

    /** @return array{as_of: string, instruments: array<int, array<string, mixed>>} */
    private function parse(string $body): array
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, ltrim($body, "\xEF\xBB\xBF"));
        rewind($stream);

        try {
            $market = fgetcsv($stream, separator: ';', escape: '');
            $date = fgetcsv($stream, separator: ';', escape: '');
            $header = fgetcsv($stream, separator: ';', escape: '');
            if ($market !== ['Market:', 'XETR'] || ($date[0] ?? null) !== 'Date Last Update:') {
                throw new RuntimeException('Unexpected instrument catalogue identity.');
            }
            $asOf = Carbon::createFromFormat('!d.m.Y', trim($date[1] ?? ''), 'Europe/Berlin');
            if ($asOf->format('d.m.Y') !== trim($date[1] ?? '') || $asOf->isFuture()
                || $asOf->lessThan(now('Europe/Berlin')->subDays(7)->startOfDay())) {
                throw new RuntimeException('Instrument catalogue date is invalid or too old.');
            }
            $required = ['Product Status', 'Instrument Status', 'Instrument', 'ISIN', 'WKN', 'Mnemonic', 'MIC Code', 'Instrument Type', 'Currency'];
            if (! is_array($header) || array_diff($required, $header) !== []) {
                throw new RuntimeException('Unexpected instrument catalogue columns.');
            }
            $columns = array_flip($header);
            $instruments = [];
            while (($row = fgetcsv($stream, separator: ';', escape: '')) !== false) {
                $value = static fn (string $field): string => trim($row[$columns[$field]] ?? '');
                if ($value('Product Status') !== 'Active' || $value('Instrument Status') !== 'Active'
                    || $value('MIC Code') !== 'XETR' || ! in_array($value('Instrument Type'), ['CS', 'ETF'], true)) {
                    continue;
                }
                $isin = Str::upper($value('ISIN'));
                $symbol = Str::upper($value('Mnemonic'));
                $currency = Str::upper($value('Currency'));
                if (! preg_match('/^[A-Z]{2}[A-Z0-9]{10}$/', $isin) || $symbol === '' || ! preg_match('/^[A-Z]{3}$/', $currency)) {
                    continue;
                }
                $wkn = Str::upper($value('WKN'));
                if (strlen($wkn) === 9 && str_starts_with($wkn, '000')) {
                    $wkn = substr($wkn, 3);
                }
                $instruments[] = [
                    'name' => $value('Instrument'), 'isin' => $isin,
                    'wkn' => preg_match('/^[A-Z0-9]{6}$/', $wkn) ? $wkn : null,
                    'valor' => null, 'symbol' => $symbol, 'exchange' => 'XETRA', 'mic_code' => 'XETR',
                    'instrument_type' => $value('Instrument Type') === 'ETF' ? 'ETF' : 'Common Stock',
                    'country' => null, 'currency' => $currency,
                    'source_key' => 'xetra_instruments', 'source_name' => 'Deutsche Börse Xetra reference data',
                    'source_url' => self::Url, 'source_as_of' => $asOf->toDateString(),
                ];
            }
            if ($instruments === []) {
                throw new RuntimeException('Instrument catalogue contains no supported active instruments.');
            }

            return ['as_of' => $asOf->toDateString(), 'instruments' => $instruments];
        } finally {
            fclose($stream);
        }
    }
}
