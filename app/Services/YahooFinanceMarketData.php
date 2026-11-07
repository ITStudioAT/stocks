<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class YahooFinanceMarketData
{
    public const CooldownKey = 'free-intraday.cooldown.yahoo';

    private const MaxBytes = 524288;

    private const MaxRetryTimestamp = 4294967295;

    public static function pauseAfter(string $retryAfter, int $minimumSeconds = 300): int
    {
        $now = now()->timestamp;
        try {
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter
                : CarbonImmutable::parse($retryAfter)->timestamp - $now;
        } catch (Throwable) {
            $seconds = $minimumSeconds;
        }

        $retryAt = $now + min(self::MaxRetryTimestamp - $now, max($minimumSeconds, $seconds));

        return Cache::lock(self::CooldownKey.':lock', 5)->block(5, function () use ($retryAt): int {
            $previous = Cache::get(self::CooldownKey);
            $expires = is_int($previous) ? max($previous, $retryAt) : $retryAt;
            Cache::put(self::CooldownKey, $expires, CarbonImmutable::createFromTimestamp($expires));

            return $expires;
        });
    }

    /** @param array<string, mixed> $item */
    public static function supports(array $item): bool
    {
        return str_starts_with((string) $item['isin'], 'US') && $item['currency'] === 'USD'
            && in_array(strtoupper((string) $item['exchange']), ['US', 'NYSE', 'NASDAQ', 'NYSE ARCA', 'NYSEARCA'], true)
            && preg_match('/\A[A-Z][A-Z0-9.-]{0,9}\z/', (string) $item['symbol']) === 1;
    }

    /** @param array<string, mixed> $item */
    public static function supportsXetra(array $item): bool
    {
        $symbol = config('services.free_intraday.yahoo_xetra_symbols.'.$item['isin']);

        return IntradayMarketIdentity::isXetra($item) && ($item['currency'] ?? null) === 'EUR'
            && is_string($symbol) && $symbol === ($item['symbol'] ?? '').'.DE';
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    public function runXetra(array $item, bool $coordinated = false): array
    {
        if (! self::supportsXetra($item)) {
            throw new RuntimeException('No verified Yahoo Xetra ISIN/symbol/currency mapping.');
        }

        return $this->run(collect([$item]), includeSeries: true, xetra: true, coordinated: $coordinated);
    }

    /** @param Collection<int, array<string, mixed>> $instruments @return array<string, mixed> */
    public function run(Collection $instruments, bool $includeSeries = false, bool $xetra = false, bool $coordinated = false): array
    {
        if (app(MarketDataMode::class)->paused()) {
            throw new MarketDataSourceBusy('Market-data deployment upgrade is in progress.');
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $started = hrtime(true) / 1e9;
        $deadline = $started + 6;
        $requests = 0;
        $warnings = Cache::has(self::CooldownKey) ? ['Yahoo Finance: Anbietersperre/Abrufpause aktiv; bitte später erneut versuchen.'] : [];
        $rateLimited = false;
        $blocked = $warnings !== [];
        $rows = [];
        foreach ($instruments as $item) {
            $row = [...$item, 'price' => null, 'trade_at' => null, 'trade_venue' => null,
                'trade_source' => 'Yahoo Finance', 'provider' => 'Yahoo Finance', 'state' => 'not_applicable',
                'us_instrument' => self::supports($item)];
            if ($xetra ? self::supportsXetra($item) : self::supports($item)) {
                $row['state'] = 'not_found';
                if ($requests >= 3 || hrtime(true) / 1e9 >= $deadline || $blocked
                    || (MarketPriceStore::enabled() && ! $coordinated
                        && ! app(MarketDataCoordinator::class)->reserveYahoo($item['symbol'].($xetra ? '.DE' : '')))) {
                    $row['quote_note'] = 'Nicht abgerufen: Anfrage-, Zeitlimit oder Anbietersperre.';
                    if (! $blocked) {
                        $warnings[] = 'Yahoo Finance: Anfrage- oder Zeitlimit erreicht; weitere US-Instrumente wurden nicht geprüft.';
                    }
                } else {
                    $requests++;
                    try {
                        $remaining = max(0.001, $deadline - hrtime(true) / 1e9);
                        $response = Http::connectTimeout(min(2, $remaining))->timeout(min(4, $remaining))
                            ->withOptions(['allow_redirects' => false,
                                'on_headers' => static function (ResponseInterface $response): void {
                                    if ((int) $response->getHeaderLine('Content-Length') > self::MaxBytes) {
                                        throw new RuntimeException('Antwort zu groß.');
                                    }
                                },
                                'progress' => static function (float $total, float $received) use ($deadline): void {
                                    if ($received > self::MaxBytes || hrtime(true) / 1e9 > $deadline) {
                                        throw new RuntimeException('Downloadlimit erreicht.');
                                    }
                                },
                            ])->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($item['symbol'].($xetra ? '.DE' : '')),
                                ['range' => $xetra ? '1mo' : '5d', 'interval' => '5m', 'includePrePost' => 'false']);
                        if (in_array($response->status(), [403, 429], true)) {
                            $rateLimited = $response->status() === 429;
                            $blocked = true;
                            self::pauseAfter($response->header('Retry-After'));
                            throw new RuntimeException('Yahoo Finance blockiert oder begrenzt den Abruf (HTTP '.$response->status().'). Keine weiteren Versuche; bitte später erneut versuchen.');
                        }
                        if (! $response->successful() || strlen($response->body()) > self::MaxBytes) {
                            throw new RuntimeException('Yahoo Finance: Abruf fehlgeschlagen oder Antwort zu groß.');
                        }
                        $chart = $response->json('chart.result.0');
                        $row = [...$row, ...$this->parse($chart, $item, $xetra)];
                        if ($includeSeries) {
                            $series = $this->series($chart);
                            $row['series'] = $series['rows'];
                            $row['series_gap_count'] = $series['invalid'];
                            if ($series['invalid'] > 0) {
                                $warnings[] = 'Yahoo Finance: '.$series['invalid'].' leere oder ungültige OHLC-Intervalle ausgelassen; Lücken bleiben erhalten.';
                            }
                        }
                    } catch (Throwable $exception) {
                        $row['quote_note'] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Yahoo Finance: Zeitlimit, Netzwerk- oder Formatfehler.';
                        $warnings[] = $row['quote_note'];
                    }
                }
            }
            $rows[] = $row;
        }
        $found = array_values(array_filter($rows, fn (array $row): bool => $row['price'] !== null));
        $times = array_column($found, 'trade_at');
        $latest = $times === [] ? null : max($times);

        return [
            'status' => $warnings !== [] ? 'partial' : ($found === [] ? 'empty' : 'ok'),
            'source' => $xetra ? 'Yahoo Finance · Xetra (inoffiziell, 15 min delayed)' : 'Yahoo Finance · US-Kurse (inoffiziell)', 'source_code' => 'YAHOO',
            'checked_at' => now()->toIso8601String(), 'search_before' => now('UTC')->toIso8601String(),
            'next_round' => null, 'rate_limited' => $rateLimited, 'provider_blocked' => $blocked,
            'catalog_generated_at' => null, 'newest_catalog_segment_at' => null,
            'latest_observed_trade_at' => $latest,
            'observed_age_minutes' => $latest === null ? null : (int) CarbonImmutable::parse($latest)->diffInMinutes(now(), absolute: true),
            'files' => [], 'files_selected' => 0, 'records_checked' => count($found), 'records_ignored' => 0,
            'matched_instruments' => count($found), 'instruments' => $rows, 'warnings' => array_values(array_unique($warnings)),
            'duration_ms' => (int) round((hrtime(true) / 1e9 - $started) * 1000),
            'coverage' => [['source' => 'Yahoo Finance · US', 'source_code' => 'YAHOO', 'round' => 0,
                'status' => $blocked || $warnings !== [] ? 'partial' : ($requests === 0 ? 'skipped' : 'ok'),
                'files' => 0, 'selected' => 0, 'requests' => $requests, 'from' => $times === [] ? null : min($times), 'to' => $latest]],
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function parse(mixed $chart, array $item, bool $xetra = false): array
    {
        $meta = is_array($chart) ? ($chart['meta'] ?? []) : [];
        if ($xetra) {
            if (! self::supportsXetra($item) || ! is_array($meta) || ($meta['symbol'] ?? null) !== $item['symbol'].'.DE'
                || ($meta['currency'] ?? null) !== 'EUR' || ($meta['exchangeName'] ?? null) !== 'GER'
                || strtoupper((string) ($meta['fullExchangeName'] ?? '')) !== 'XETRA'
                || ($meta['exchangeTimezoneName'] ?? null) !== 'Europe/Berlin'
                || ! in_array($meta['instrumentType'] ?? null, ['ETF', 'EQUITY'], true)) {
                throw new RuntimeException('Yahoo Xetra identity/currency/venue mismatch; quote rejected.');
            }
        } elseif (! is_array($meta) || ($meta['symbol'] ?? null) !== $item['symbol'] || ($meta['currency'] ?? null) !== 'USD'
            || ($item['symbol'] === 'ARGT' && $item['isin'] !== 'US37950E2596')
            || ! in_array($meta['exchangeName'] ?? null, ['PCX', 'NYQ', 'NMS', 'NGM', 'NCM', 'NAS', 'ASE', 'BTS', 'BATS'], true)
            || ($meta['exchangeTimezoneName'] ?? null) !== 'America/New_York'
            || ! in_array($meta['instrumentType'] ?? null, ['ETF', 'EQUITY'], true)
            || ($item['isin'] === 'US37950E2596' && ($item['symbol'] !== 'ARGT' || $meta['exchangeName'] !== 'PCX' || $meta['instrumentType'] !== 'ETF'))) {
            throw new RuntimeException('Yahoo Finance: Ticker, Währung oder US-Börsenidentität passt nicht. Kein Kurs übernommen.');
        }
        $price = $meta['regularMarketPrice'] ?? null;
        $timestamp = $meta['regularMarketTime'] ?? null;
        if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0
            || ! is_int($timestamp) || $timestamp <= 0 || $timestamp > now()->timestamp) {
            throw new RuntimeException('Yahoo Finance: Kein gültiger letzter regulärer Kurs mit Datenzeit vorhanden.');
        }
        $at = CarbonImmutable::createFromTimestampUTC($timestamp);
        $stale = $at->lessThan(now()->subDays(4));

        return ['state' => 'found', 'price' => (string) $price, 'trade_at' => $at->format('Y-m-d\TH:i:s.u\Z'),
            'price_type' => 'regular_market_last', 'quote_venue' => substr((string) ($meta['fullExchangeName'] ?? $meta['exchangeName']), 0, 80),
            'listing_mic' => $xetra ? 'XETR' : ($meta['exchangeName'] === 'PCX' ? 'ARCX' : null),
            'quote_stale' => $stale, 'quote_note' => $xetra
                ? 'Verified ISIN/symbol/Xetra/EUR mapping; provider OHLC, not individual trades. 15 min delayed; completeness unverified.'
                : ($stale ? 'Veralteter Quellwert (älter als vier Tage). ' : '').
                'Letzter regulärer Marktkurs, kein einzelner verifizierter Trade. Möglicherweise verzögert; Verzögerung unbekannt. '.
                'Ticker aus US-Bestand geprüft; Yahoo bestätigt keine ISIN und keinen Ausführungs-MIC.'];
    }

    /** @param array<string, mixed> $chart @return array{rows: array<int, array<string, mixed>>, invalid: int} */
    private function series(array $chart): array
    {
        if (($chart['meta']['dataGranularity'] ?? null) !== '5m') {
            throw new RuntimeException('Yahoo Finance: unexpected OHLC interval; no five-minute candles accepted.');
        }
        $prices = $chart['indicators']['quote'][0] ?? [];
        $rows = [];
        $invalid = 0;
        $xetra = ($chart['meta']['exchangeName'] ?? null) === 'GER';
        foreach (array_slice($chart['timestamp'] ?? [], -5000, preserve_keys: true) as $index => $timestamp) {
            if (! is_int($timestamp) || $timestamp % 300 !== 0 || $timestamp + 300 > now()->timestamp) {
                continue;
            }
            $date = CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($xetra ? 'Europe/Berlin' : 'America/New_York');
            if (! $date->isWeekday() || $date->format('H:i') < ($xetra ? '09:00' : '09:30') || $date->format('H:i') >= ($xetra ? '17:36' : '16:00')) {
                continue;
            }
            $values = [];
            foreach (['open', 'high', 'low', 'close'] as $key) {
                $value = $prices[$key][$index] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                    $invalid++;

                    continue 2;
                }
                $values[$key] = number_format((float) $value, 8, '.', '');
            }
            if ((float) $values['high'] < max((float) $values['open'], (float) $values['close'])
                || (float) $values['low'] > min((float) $values['open'], (float) $values['close'])) {
                $invalid++;

                continue;
            }
            $volume = $prices['volume'][$index] ?? null;
            $rows[$timestamp] = ['timestamp' => $timestamp, 'datetime' => gmdate('Y-m-d H:i:s', $timestamp),
                ...$values, 'volume' => is_int($volume) && $volume >= 0 ? $volume : null,
                'interval' => '5m', 'data_kind' => 'provider_ohlc', 'currency' => $xetra ? 'EUR' : 'USD',
                'source_key' => $xetra ? 'yahoo_xetra_intraday' : 'yahoo_intraday', 'source_name' => $xetra ? 'Yahoo Finance Xetra 5m OHLC · 15 min delayed' : 'Yahoo Finance 5m OHLC · delay unknown',
                'source_url' => 'https://finance.yahoo.com/quote/'.rawurlencode($chart['meta']['symbol']).'/',
                'venue' => $chart['meta']['fullExchangeName'] ?? $chart['meta']['exchangeName'],
                'mic' => $xetra ? 'XETR' : null, 'coverage_complete' => false, 'delay_minutes' => $xetra ? 15 : null];
        }
        ksort($rows);

        return ['rows' => array_values($rows), 'invalid' => $invalid];
    }
}
