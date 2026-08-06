<?php

namespace Tests\Feature;

use App\Jobs\SyncStockEodhdData;
use App\Models\StockEodhdSyncRun;
use App\Models\StockHoldingIntradayReloadRun;
use App\Models\User;
use App\Services\EodhdEndOfDayDataService;
use App\Services\StockHoldingIntradayDataReloader;
use App\Services\V2StockEodhdSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminV2StockEodhdSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_reuse_and_restore_a_stock_eodhd_sync(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-06 21:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson('/admin/v2/stocks/eodhd-sync');

        $response
            ->assertAccepted()
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.stage', 'eod')
            ->assertJsonPath('refresh.date_from', '2025-08-06')
            ->assertJsonPath('refresh.date_to', '2026-08-06')
            ->assertJsonPath('refresh.steps.0.label', 'EOD-Daten')
            ->assertJsonPath('refresh.steps.1.label', 'Intraday-Daten');

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushed(
            SyncStockEodhdData::class,
            fn (SyncStockEodhdData $job): bool => $job->refreshId === $refreshId,
        );

        $this->actingAs($admin)
            ->postJson('/admin/v2/stocks/eodhd-sync')
            ->assertAccepted()
            ->assertJsonPath('refresh.refresh_id', $refreshId);

        Queue::assertPushed(SyncStockEodhdData::class, 1);

        $this->actingAs($admin)
            ->getJson('/admin/v2/stocks/eodhd-sync')
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', $refreshId);

        $this->actingAs($admin)
            ->getJson("/admin/v2/stocks/eodhd-sync/{$refreshId}")
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', $refreshId);
    }

    public function test_sync_runs_eod_then_intraday_and_reports_visible_progress(): void
    {
        Http::fake();
        $this->travelTo(Carbon::parse('2026-08-06 21:00:00', 'Europe/Vienna'));

        $this->mock(EodhdEndOfDayDataService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncAll')
                ->once()
                ->withArgs(fn (Carbon $date): bool => $date->toDateString() === '2026-08-06')
                ->andReturn([
                    'requested_count' => 2,
                    'stored_count' => 40,
                    'skipped_count' => 1,
                    'failed_count' => 0,
                    'date_from' => '2025-08-06',
                    'date_to' => '2026-08-06',
                    'errors' => [],
                ]);
        });
        $this->mock(StockHoldingIntradayDataReloader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMissingYearRun')
                ->once()
                ->withArgs(fn (Carbon $date): bool => $date->toDateString() === '2026-08-06')
                ->andReturnUsing(fn (): StockHoldingIntradayReloadRun => StockHoldingIntradayReloadRun::query()->create([
                    'id' => 'intraday-child-test',
                    'status' => 'queued',
                    'total_count' => 3,
                    'date_from' => '2025-08-07',
                    'date_to' => '2026-08-06',
                    'message' => 'Queued.',
                ]));
            $mock->shouldReceive('importMissingYear')
                ->once()
                ->with('intraday-child-test')
                ->andReturnUsing(function (): void {
                    StockHoldingIntradayReloadRun::query()->findOrFail('intraday-child-test')->update([
                        'status' => 'finished',
                        'processed_count' => 3,
                        'success_count' => 3,
                        'stored_count' => 900,
                        'message' => '900 intraday candles loaded/updated for 3 stocks.',
                        'finished_at' => now(),
                    ]);
                });
        });

        $service = app(V2StockEodhdSyncService::class);
        $run = $service->createRun();
        $service->run($run->id);
        $payload = $service->payload($run->refresh());

        $this->assertSame('finished', $payload['status']);
        $this->assertSame('finished', $payload['steps'][0]['status']);
        $this->assertSame('finished', $payload['steps'][1]['status']);
        $this->assertSame(40, $payload['eod']['stored_count']);
        $this->assertSame(900, $payload['intraday']['stored_count']);
        $this->assertSame(4, $payload['progress']['completed']);
        $this->assertSame(4, $payload['progress']['total']);
        $this->assertSame(100, $payload['progress']['percent']);
        $this->assertDatabaseCount('stock_realtime_prices', 0);
        $this->assertDatabaseCount('stock_holding_intraday_prices', 0);
        Http::assertNothingSent();
    }

    public function test_sync_reports_partial_failures_from_both_stored_data_phases(): void
    {
        $this->mock(EodhdEndOfDayDataService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncAll')->once()->andReturn([
                'requested_count' => 1,
                'stored_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 1,
                'date_from' => '2025-08-06',
                'date_to' => '2026-08-06',
                'errors' => ['EOD failed for TEST.'],
            ]);
        });
        $this->mock(StockHoldingIntradayDataReloader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createMissingYearRun')->once()->andReturnUsing(
                fn (): StockHoldingIntradayReloadRun => StockHoldingIntradayReloadRun::query()->create([
                    'id' => 'intraday-child-partial',
                    'status' => 'queued',
                    'total_count' => 1,
                    'date_from' => '2025-08-07',
                    'date_to' => '2026-08-06',
                ]),
            );
            $mock->shouldReceive('importMissingYear')->once()->andReturnUsing(function (): void {
                StockHoldingIntradayReloadRun::query()->findOrFail('intraday-child-partial')->update([
                    'status' => 'failed',
                    'processed_count' => 1,
                    'failed_count' => 1,
                    'message' => 'Intraday reload failed.',
                    'error_summary' => ['message' => 'Intraday failed for TEST.'],
                    'finished_at' => now(),
                ]);
            });
        });

        $service = app(V2StockEodhdSyncService::class);
        $run = $service->createRun();
        $service->run($run->id);
        $run->refresh();

        $this->assertSame('partial', $run->status);
        $this->assertSame('partial', $run->steps[0]['status']);
        $this->assertSame('failed', $run->steps[1]['status']);
        $this->assertStringContainsString('EOD failed for TEST.', $run->error);
        $this->assertStringContainsString('Intraday failed for TEST.', $run->error);
    }

    public function test_guest_cannot_start_or_read_stock_eodhd_sync_runs(): void
    {
        $run = StockEodhdSyncRun::query()->create([
            'id' => 'stock-eodhd-test',
            'date_from' => '2025-08-06',
            'date_to' => '2026-08-06',
            'steps' => [],
        ]);

        $this->postJson('/admin/v2/stocks/eodhd-sync')->assertUnauthorized();
        $this->getJson('/admin/v2/stocks/eodhd-sync')->assertUnauthorized();
        $this->getJson("/admin/v2/stocks/eodhd-sync/{$run->id}")->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
