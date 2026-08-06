<?php

namespace Tests\Feature;

use App\Jobs\SyncIndexEodhdData;
use App\Jobs\SyncV2IndexRealtimeData;
use App\Models\AppConfig;
use App\Models\IndexEodhdSyncRun;
use App\Models\IndexWatchItem;
use App\Models\User;
use App\Services\IndexWatchItemPriceRefresher;
use App\Services\V2IndexRealtimeScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class V2IndexEodhdSyncScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_the_independent_v2_index_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 10:15:00', 'Europe/Vienna'));
        $completedAt = Carbon::parse('2026-08-05 09:45:00', 'Europe/Vienna');
        $this->syncRun([
            'status' => 'finished',
            'started_at' => $completedAt->copy()->subMinutes(10),
            'finished_at' => $completedAt,
        ]);
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/admin/v2/indices/eodhd-sync-settings')
            ->assertOk()
            ->assertJsonPath('index_eodhd_sync_settings.times', ['02:00'])
            ->assertJsonPath('index_eodhd_sync_settings.timezone', 'Europe/Vienna')
            ->assertJsonPath('index_eodhd_sync_settings.latest_update_at', '2026-08-05T09:45:00+02:00')
            ->assertJsonPath('index_eodhd_sync_settings.next_update_at', '2026-08-06T02:00:00+02:00')
            ->assertJsonPath('index_eodhd_sync_settings.status', 'waiting')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.trading_interval_minutes', 20)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.closed_refresh_enabled', false)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.timezone', 'Europe/Vienna')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'due')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_label', 'Due now')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_detail', 'Waiting for the minute scheduler to enqueue the refresh.');

        $this->actingAs($admin)
            ->patchJson('/admin/v2/indices/eodhd-sync-settings', [
                'times' => ['18:45', '06:30'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Automatic index update times saved.')
            ->assertJsonPath('index_eodhd_sync_settings.times', ['06:30', '18:45'])
            ->assertJsonPath('index_eodhd_sync_settings.next_update_at', '2026-08-05T18:45:00+02:00');

        $config = AppConfig::query()->where('key', 'v2_index_eodhd_sync.schedule')->firstOrFail();

        $this->assertSame(['06:30', '18:45'], $config->value['times']);

        $this->actingAs($admin)
            ->patchJson('/admin/v2/indices/eodhd-sync-settings', [
                'realtime' => [
                    'trading_interval_minutes' => 5,
                    'trading_starts_before_minutes' => 15,
                    'trading_ends_after_minutes' => 30,
                    'closed_refresh_enabled' => true,
                    'closed_interval_minutes' => 120,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Automatic index realtime schedule saved.')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.trading_interval_minutes', 5)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.trading_starts_before_minutes', 15)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.trading_ends_after_minutes', 30)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.closed_refresh_enabled', true)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.closed_interval_minutes', 120);

        $realtimeConfig = AppConfig::query()->where('key', 'v2_index_realtime.schedule')->firstOrFail();

        $this->assertSame(5, $realtimeConfig->value['trading_interval_minutes']);
        $this->assertTrue($realtimeConfig->value['closed_refresh_enabled']);
    }

    public function test_due_v2_index_realtime_schedule_queues_the_live_sync(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 5,
                'trading_starts_before_minutes' => 15,
                'trading_ends_after_minutes' => 30,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_refreshed_at' => null,
                'last_finished_at' => null,
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T10:00:00+02:00',
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/v2/indices/eodhd-sync-settings')
            ->assertOk()
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'due')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_label', 'Due now')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_detail', 'Waiting for the minute scheduler to enqueue the refresh.');

        $this->artisan('indices:v2-realtime:dispatch-due')
            ->expectsOutput('Queued 1 v2 index realtime synchronization(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SyncV2IndexRealtimeData::class, 1);

        $config = AppConfig::query()->where('key', 'v2_index_realtime.schedule')->firstOrFail();

        $this->assertSame('2026-08-05T10:00:00+02:00', $config->value['last_dispatched_at']);
        $this->assertSame('2026-08-05T10:05:00+02:00', $config->value['next_refresh_at']);

        $this->artisan('indices:v2-realtime:dispatch-due')
            ->expectsOutput('Queued 0 v2 index realtime synchronization(s).')
            ->assertExitCode(0);
        Queue::assertPushed(SyncV2IndexRealtimeData::class, 1);
    }

    public function test_admin_can_immediately_queue_an_overdue_v2_index_realtime_sync_once(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:01:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 5,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-05T09:55:00+02:00',
                'last_refreshed_at' => '2026-08-05T09:55:00+02:00',
                'last_finished_at' => '2026-08-05T09:55:00+02:00',
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T10:00:00+02:00',
            ],
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/v2/indices/realtime-sync')
            ->assertAccepted()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'updating')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.next_refresh_at', '2026-08-05T10:06:00+02:00');

        $this->actingAs($admin)
            ->postJson('/admin/v2/indices/realtime-sync')
            ->assertOk()
            ->assertJsonPath('queued', false);

        Queue::assertPushed(SyncV2IndexRealtimeData::class, 1);

        $settings = AppConfig::query()->where('key', 'v2_index_realtime.schedule')->firstOrFail()->value;

        $this->assertSame('2026-08-05T10:01:00+02:00', $settings['last_dispatched_at']);
        $this->assertSame('2026-08-05T10:06:00+02:00', $settings['next_refresh_at']);
    }

    public function test_admin_cannot_queue_a_v2_index_realtime_sync_before_it_is_due(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:01:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 5,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-05T10:00:00+02:00',
                'last_refreshed_at' => '2026-08-05T10:00:00+02:00',
                'last_finished_at' => '2026-08-05T10:00:00+02:00',
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T10:05:00+02:00',
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/v2/indices/realtime-sync')
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'scheduled');

        Queue::assertNothingPushed();
    }

    public function test_v2_index_realtime_status_explains_when_markets_are_closed(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 20:00:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/v2/indices/eodhd-sync-settings')
            ->assertOk()
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'market_closed')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_label', 'Market closed')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_detail', 'Closed-market refreshes are off; updates resume in the next trading window.');
    }

    public function test_v2_index_realtime_schedule_uses_the_us_market_timezone_after_europe_closes(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 18:40:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'DJI',
            'trading_times' => 'Monday-Friday 09:30-16:00 America/New_York',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-06T18:26:04+02:00',
                'last_refreshed_at' => '2026-08-06T18:26:14+02:00',
                'last_finished_at' => '2026-08-06T18:26:14+02:00',
                'last_error' => null,
                'next_refresh_at' => '2026-08-07T08:00:00+02:00',
            ],
        ]);

        $payload = app(V2IndexRealtimeScheduler::class)->payload();

        $this->assertTrue($payload['is_trading_time']);
        $this->assertSame('scheduled', $payload['status']);
        $this->assertSame('2026-08-06T18:46:14+02:00', $payload['next_refresh_at']);
    }

    public function test_v2_index_realtime_schedule_uses_the_earliest_asian_market_window(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 23:30:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => '000001',
            'trading_times' => 'Monday-Friday 09:30-15:00 Asia/Shanghai',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'N225',
            'trading_times' => 'Monday-Friday 09:00-15:30 Asia/Tokyo',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-06T18:26:04+02:00',
                'last_refreshed_at' => '2026-08-06T18:26:14+02:00',
                'last_finished_at' => '2026-08-06T18:26:14+02:00',
                'last_error' => null,
                'next_refresh_at' => '2026-08-07T08:00:00+02:00',
            ],
        ]);

        $payload = app(V2IndexRealtimeScheduler::class)->payload();

        $this->assertFalse($payload['is_trading_time']);
        $this->assertSame('market_closed', $payload['status']);
        $this->assertSame('2026-08-07T01:00:00+02:00', $payload['next_refresh_at']);
    }

    public function test_v2_index_realtime_sync_refreshes_only_indices_in_their_own_market_window(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 18:40:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        $usIndex = IndexWatchItem::factory()->create([
            'symbol' => 'DJI',
            'trading_times' => 'Monday-Friday 09:30-16:00 America/New_York',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'N225',
            'trading_times' => 'Monday-Friday 09:00-15:30 Asia/Tokyo',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_refreshed_at' => null,
                'last_finished_at' => null,
                'last_error' => null,
                'next_refresh_at' => '2026-08-06T18:40:00+02:00',
            ],
        ]);
        $this->mock(IndexWatchItemPriceRefresher::class)
            ->expects('refreshIds')
            ->once()
            ->with([$usIndex->id])
            ->andReturn([
                'requested_count' => 1,
                'refreshed_count' => 1,
                'failed_count' => 0,
            ]);

        $result = app(V2IndexRealtimeScheduler::class)->syncNow();

        $this->assertSame([
            'requested_count' => 1,
            'refreshed_count' => 1,
            'failed_count' => 0,
        ], $result);
    }

    public function test_v2_index_realtime_status_explains_a_scheduled_refresh(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 10:15:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-05T10:00:00+02:00',
                'last_refreshed_at' => '2026-08-05T10:00:00+02:00',
                'last_finished_at' => '2026-08-05T10:00:00+02:00',
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T10:20:00+02:00',
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/v2/indices/eodhd-sync-settings')
            ->assertOk()
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status', 'scheduled')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_label', 'Scheduled')
            ->assertJsonPath('index_eodhd_sync_settings.realtime.status_detail', 'No job is running; the next refresh starts at the time shown below.');
    }

    public function test_stale_v2_index_realtime_dispatch_does_not_block_the_next_refresh(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 5,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => '2026-08-05T09:40:00+02:00',
                'last_refreshed_at' => null,
                'last_finished_at' => null,
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T09:45:00+02:00',
            ],
        ]);

        $this->artisan('indices:v2-realtime:dispatch-due')
            ->expectsOutput('Queued 1 v2 index realtime synchronization(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SyncV2IndexRealtimeData::class, 1);
        $settings = AppConfig::query()->where('key', 'v2_index_realtime.schedule')->firstOrFail()->value;
        $this->assertSame('2026-08-05T10:00:00+02:00', $settings['last_dispatched_at']);
    }

    public function test_sync_queue_keeps_v2_index_realtime_completion_state(): void
    {
        config()->set('queue.default', 'sync');
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00', 'Europe/Vienna'));
        $index = IndexWatchItem::factory()->create([
            'trading_times' => '09:00 - 17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'trading_interval_minutes' => 5,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_refreshed_at' => null,
                'last_finished_at' => null,
                'last_error' => null,
                'next_refresh_at' => '2026-08-05T10:00:00+02:00',
            ],
        ]);
        $this->mock(IndexWatchItemPriceRefresher::class)
            ->expects('refreshIds')
            ->once()
            ->with([$index->id])
            ->andReturn([
                'requested_count' => 1,
                'refreshed_count' => 1,
                'failed_count' => 0,
            ]);

        $this->artisan('indices:v2-realtime:dispatch-due')
            ->expectsOutput('Queued 1 v2 index realtime synchronization(s).')
            ->assertExitCode(0);

        $settings = AppConfig::query()->where('key', 'v2_index_realtime.schedule')->firstOrFail()->value;
        $this->assertSame('2026-08-05T10:00:00+02:00', $settings['last_dispatched_at']);
        $this->assertSame('2026-08-05T10:00:00+02:00', $settings['last_refreshed_at']);
        $this->assertSame('2026-08-05T10:00:00+02:00', $settings['last_finished_at']);
        $this->assertSame('scheduled', app(V2IndexRealtimeScheduler::class)->payload()['status']);
    }

    public function test_due_v2_index_schedule_queues_the_sync_using_the_stored_times(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00', 'Europe/Vienna'));
        AppConfig::query()->create([
            'key' => 'v2_index_eodhd_sync.schedule',
            'value' => [
                'times' => ['10:00', '18:00'],
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'next_update_at' => '2026-08-05T10:00:00+02:00',
            ],
        ]);

        $this->artisan('indices:eodhd-sync:dispatch-due')
            ->expectsOutput('Queued 1 v2 index EODHD synchronization(s).')
            ->assertExitCode(0);

        $run = IndexEodhdSyncRun::query()->sole();
        Queue::assertPushed(
            SyncIndexEodhdData::class,
            fn (SyncIndexEodhdData $job): bool => $job->refreshId === $run->id,
        );

        $config = AppConfig::query()->where('key', 'v2_index_eodhd_sync.schedule')->firstOrFail();

        $this->assertSame('2026-08-05T10:00:00+02:00', $config->value['last_dispatched_at']);
        $this->assertSame('2026-08-05T18:00:00+02:00', $config->value['next_update_at']);

        $this->artisan('indices:eodhd-sync:dispatch-due')
            ->expectsOutput('Queued 0 v2 index EODHD synchronization(s).')
            ->assertExitCode(0);
        Queue::assertPushed(SyncIndexEodhdData::class, 1);
    }

    public function test_invalid_or_duplicate_v2_index_update_times_are_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->patchJson('/admin/v2/indices/eodhd-sync-settings', [
                'times' => ['08:00', '08:00', '25:00'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['times.1', 'times.2']);
    }

    public function test_invalid_v2_index_realtime_schedule_is_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->patchJson('/admin/v2/indices/eodhd-sync-settings', [
                'realtime' => [
                    'trading_interval_minutes' => 0,
                    'trading_starts_before_minutes' => -1,
                    'trading_ends_after_minutes' => 721,
                    'closed_refresh_enabled' => 'yes',
                    'closed_interval_minutes' => 0,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'realtime.trading_interval_minutes',
                'realtime.trading_starts_before_minutes',
                'realtime.trading_ends_after_minutes',
                'realtime.closed_refresh_enabled',
                'realtime.closed_interval_minutes',
            ]);
    }

    public function test_due_slot_is_skipped_when_a_v2_index_sync_is_already_running(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00', 'Europe/Vienna'));
        AppConfig::query()->create([
            'key' => 'v2_index_eodhd_sync.schedule',
            'value' => [
                'times' => ['10:00', '18:00'],
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'next_update_at' => '2026-08-05T10:00:00+02:00',
            ],
        ]);
        $this->syncRun(['status' => 'running', 'started_at' => now()]);

        $this->artisan('indices:eodhd-sync:dispatch-due')
            ->expectsOutput('Queued 0 v2 index EODHD synchronization(s).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();

        $config = AppConfig::query()->where('key', 'v2_index_eodhd_sync.schedule')->firstOrFail();

        $this->assertNull($config->value['last_dispatched_at']);
        $this->assertSame('2026-08-05T18:00:00+02:00', $config->value['next_update_at']);
    }

    public function test_guest_cannot_view_or_update_the_v2_index_schedule(): void
    {
        $this->getJson('/admin/v2/indices/eodhd-sync-settings')->assertUnauthorized();
        $this->patchJson('/admin/v2/indices/eodhd-sync-settings', ['times' => ['08:00']])->assertUnauthorized();
        $this->postJson('/admin/v2/indices/realtime-sync')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRun(array $attributes = []): IndexEodhdSyncRun
    {
        return IndexEodhdSyncRun::query()->create([
            'id' => 'index-eodhd-test-'.fake()->uuid(),
            'date_from' => '2025-08-06',
            'date_to' => '2026-08-05',
            'steps' => [],
            ...$attributes,
        ]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
