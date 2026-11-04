<?php

namespace Tests;

use App\Models\StockHolding;
use App\Models\User;
use App\Services\AdminSessionManager;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException(
                'Refusing to run tests while Laravel configuration is cached. Run "php artisan config:clear" first so PHPUnit can use its isolated test database.',
            );
        }

        parent::setUp();

        Http::preventStrayRequests();
        config(['services.free_intraday.request_interval_ms' => 0]);

        $this->withoutVite();
    }

    public function actingAs(UserContract $user, mixed $guard = null): static
    {
        parent::actingAs($user, $guard);

        $guardName = is_string($guard) ? $guard : config('auth.defaults.guard');

        if ($user instanceof User && $guardName === 'web') {
            $this->withSession([
                AdminSessionManager::TargetRevisionSessionKey => $user->auth_revision,
            ]);
        }

        return $this;
    }

    /** @param array<int, array{holding: StockHolding, price: float, at: string, mic?: string}> $trades */
    protected function fakeEuropeanTrades(array $trades): void
    {
        Storage::fake('local');
        $files = [];
        $times = [];
        foreach ($trades as $trade) {
            $date = Carbon::parse($trade['at'], 'UTC');
            $times[] = $date->copy()->setTimezone('Europe/Berlin')->format('H:i');
            $name = 'DETR-posttrade-'.$date->format('Y-m-d\TH_i').'.json.gz';
            $files[$name][] = json_encode(['messageId' => 'posttrade', 'instrumentIdentificationCode' => $trade['holding']->isin,
                'priceCurrency' => $trade['holding']->currency, 'price' => $trade['price'], 'priceNotation' => 1,
                'venueOfExecution' => $trade['mic'] ?? 'XETA', 'tradingDateAndTime' => $date->format('Y-m-d\TH:i:s.000000000\Z')], JSON_THROW_ON_ERROR);
        }
        $start = $times === [] ? '09:00' : min($times);
        $end = Carbon::parse($times === [] ? '09:00' : max($times))->addMinute()->format('H:i');
        config(['services.free_intraday.session_start' => $start, 'services.free_intraday.session_end' => $end,
            'services.free_intraday.batch_files' => 1200, 'services.free_intraday.batch_seconds' => 60]);
        $today = now('Europe/Berlin')->startOfDay();
        $previous = $today->copy()->subWeekdays(1);
        foreach ([$previous, $today] as $day) {
            $from = $day->copy()->setTimeFromTimeString($start)->utc();
            $until = $day->copy()->setTimeFromTimeString($end)->utc();
            if ($day->lessThan($today)) {
                $dayRows = [];
                foreach ($files as $filename => $rows) {
                    if (str_contains($filename, $day->toDateString())) {
                        $dayRows = [...$dayRows, ...$rows];
                    }
                }
                $files['DETR-posttrade-daily-'.$day->toDateString().'.json.gz'] = $dayRows;

                continue;
            }
            for ($minute = $from->copy(); $minute->lessThan($until); $minute->addMinute()) {
                $files['DETR-posttrade-'.$minute->format('Y-m-d\TH_i').'.json.gz'] ??= [];
            }
        }
        Http::fake(function ($request) use ($files) {
            $name = basename(parse_url($request->url(), PHP_URL_PATH));
            if (str_ends_with($name, '-posttrade')) {
                return Http::response(['SourcePrefix' => $name, 'CurrentFiles' => $name === 'DETR-posttrade' ? array_keys($files) : []]);
            }

            return Http::response(gzencode(implode("\n", $files[$name] ?? [])));
        });
    }

    /** @param array<string, array{price: float, at: string}> $quotes */
    protected function fakeYahooMarketData(array $quotes): void
    {
        Http::fake(function ($request) use ($quotes) {
            $symbol = basename(parse_url($request->url(), PHP_URL_PATH));
            $quote = $quotes[$symbol];
            $price = $quote['price'];
            $timestamp = Carbon::parse($quote['at'], 'UTC')->timestamp;

            return Http::response(['chart' => ['result' => [[
                'meta' => ['symbol' => $symbol, 'currency' => 'USD', 'exchangeName' => $symbol === 'ARGT' ? 'PCX' : 'NMS',
                    'fullExchangeName' => $symbol === 'ARGT' ? 'NYSEArca' : 'Nasdaq', 'exchangeTimezoneName' => 'America/New_York',
                    'instrumentType' => $symbol === 'ARGT' ? 'ETF' : 'EQUITY', 'regularMarketPrice' => $price, 'regularMarketTime' => $timestamp, 'dataGranularity' => '5m'],
                'timestamp' => [$timestamp], 'indicators' => ['quote' => [[
                    'open' => [$price], 'high' => [$price], 'low' => [$price], 'close' => [$price], 'volume' => [10],
                ]]],
            ]]]]);
        });
    }
}
