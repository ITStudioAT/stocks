<?php

namespace Tests;

use App\Models\StockHolding;
use App\Models\User;
use App\Services\AdminSessionManager;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        $files = [];
        foreach ($trades as $trade) {
            $date = Carbon::parse($trade['at'], 'UTC');
            $name = 'DETR-posttrade-'.$date->format('Y-m-d\TH_i').'.json.gz';
            $files[$name][] = json_encode(['messageId' => 'posttrade', 'instrumentIdentificationCode' => $trade['holding']->isin,
                'priceCurrency' => $trade['holding']->currency, 'price' => $trade['price'], 'priceNotation' => 1,
                'venueOfExecution' => $trade['mic'] ?? 'XETA', 'tradingDateAndTime' => $date->format('Y-m-d\TH:i:s.000000000\Z')], JSON_THROW_ON_ERROR);
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
