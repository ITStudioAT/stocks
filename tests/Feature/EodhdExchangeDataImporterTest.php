<?php

namespace Tests\Feature;

use App\Models\EodhdExchange;
use App\Services\EodhdExchangeDataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EodhdExchangeDataImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_detail_code_uses_operating_mic_from_exchange_list(): void
    {
        $importer = app(EodhdExchangeDataImporter::class);

        $this->assertSame('XPAR', $importer->exchangeDetailCode([
            'Code' => 'PA',
            'Name' => 'Euronext Paris',
            'OperatingMIC' => 'XPAR',
        ]));
    }

    public function test_importer_stores_exchanges_with_trading_hours_and_holidays(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/exchanges-list/*' => Http::response([
                [
                    'Code' => 'BA',
                    'Name' => 'Buenos Aires Exchange',
                    'Country' => 'Argentina',
                    'Currency' => 'ARS',
                    'Timezone' => 'America/Argentina/Buenos_Aires',
                    'OperatingMIC' => 'XBUE',
                ],
                [
                    'Code' => 'NASDAQ',
                    'Name' => 'NASDAQ',
                    'Country' => 'USA',
                    'Currency' => 'USD',
                    'Timezone' => 'America/New_York',
                    'OperatingMIC' => 'XNAS',
                ],
            ]),
            'eodhd.com/api/v2/exchange-details/XBUE*' => Http::response([
                'data' => [
                    'Name' => 'Buenos Aires Stock Exchange (BYMA)',
                    'Code' => 'XBUE',
                    'Timezone' => 'America/Argentina/Buenos_Aires',
                    'TradingHours' => [
                        'Open' => '11:00:00',
                        'Close' => '17:00:00',
                        'WorkingDays' => 'Mon, Tue, Wed, Thu, Fri',
                        'PreMarketOpen' => '10:00:00',
                        'PreMarketClose' => '11:00:00',
                    ],
                    'ExchangeHolidays' => [
                        '2026-01-01' => [
                            'Holiday' => 'New Year\'s Day',
                            'Type' => 'Official',
                        ],
                        '2026-12-24' => [
                            'Holiday' => 'Christmas Eve',
                            'Type' => 'EarlyClose',
                            'EarlyClose' => '13:00:00',
                        ],
                    ],
                ],
                'meta' => [],
                'links' => [],
            ]),
            'eodhd.com/api/v2/exchange-details/XNAS*' => Http::response([
                'data' => [
                    'TradingHours' => [
                        'Open' => '09:30:00',
                        'Close' => '16:00:00',
                        'WorkingDays' => 'Mon, Tue, Wed, Thu, Fri',
                    ],
                    'ExchangeHolidays' => [
                        '2026-07-04' => [
                            'Holiday' => 'Independence Day',
                            'Type' => 'Official',
                        ],
                    ],
                ],
            ]),
        ]);
        $importer = app(EodhdExchangeDataImporter::class);
        $run = $importer->createRun();

        $importer->import($run->id);

        $run->refresh();
        $this->assertSame('finished', $run->status);
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(2, $run->success_count);

        $buenosAires = EodhdExchange::query()->where('code', 'BA')->firstOrFail();
        $nasdaq = EodhdExchange::query()->where('code', 'NASDAQ')->firstOrFail();

        $this->assertSame('XBUE', $buenosAires->detail_code);
        $this->assertSame('11:00:00', $buenosAires->trading_hours['Open']);
        $this->assertSame('10:00:00', $buenosAires->trading_hours['PreMarketOpen']);
        $this->assertSame('New Year\'s Day', $buenosAires->holidays['2026-01-01']['Holiday']);
        $this->assertSame('13:00:00', $buenosAires->holidays['2026-12-24']['EarlyClose']);
        $this->assertSame('XNAS', $nasdaq->detail_code);
        $this->assertSame('09:30:00', $nasdaq->trading_hours['Open']);
        $this->assertSame('Independence Day', $nasdaq->holidays['2026-07-04']['Holiday']);

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/v2/exchange-details/XBUE?',
        ));
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://eodhd.com/api/v2/exchange-details/XNAS?',
        ));
    }

    public function test_importer_updates_existing_exchange_rows(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        EodhdExchange::query()->create([
            'code' => 'XETRA',
            'detail_code' => 'XETR',
            'name' => 'Old name',
        ]);
        Http::fake([
            'eodhd.com/api/exchanges-list/*' => Http::response([
                [
                    'Code' => 'XETRA',
                    'Name' => 'Updated XETRA',
                    'Country' => 'Germany',
                    'Currency' => 'EUR',
                    'OperatingMIC' => 'XETR',
                ],
            ]),
            'eodhd.com/api/v2/exchange-details/XETR*' => Http::response([
                'TradingHours' => ['Open' => '09:00:00'],
                'Holidays' => [],
            ]),
        ]);
        $importer = app(EodhdExchangeDataImporter::class);
        $run = $importer->createRun();

        $importer->import($run->id);

        $this->assertSame(1, EodhdExchange::query()->count());
        $this->assertDatabaseHas('eodhd_exchanges', [
            'code' => 'XETRA',
            'name' => 'Updated XETRA',
        ]);
    }
}
