<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreeOnlyOperationsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('paidOperations')]
    public function test_paid_operations_are_explicitly_unavailable_without_requests_or_jobs(string $path): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'), Role::findOrCreate('super_admin'));
        $holding = StockHolding::factory()->create();
        $index = IndexWatchItem::factory()->create();
        $path = str_replace(['{holding}', '{index}'], [(string) $holding->id, (string) $index->id], $path);
        config(['services.eodhd.enabled' => true, 'services.eodhd.key' => 'legacy-token', 'services.market_data' => null]);
        Queue::fake();
        Http::fake();

        $this->actingAs($user)->postJson($path)->assertUnprocessable()
            ->assertJsonPath('message', 'EODHD is disabled. No free provider is available for this operation; stored data is preserved.');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function paidOperations(): array
    {
        return [
            'index chart' => ['/admin/index-watch-items/{index}/prices/ensure'],
            'index sync' => ['/admin/v2/indices/eodhd-sync'],
            'index realtime' => ['/admin/v2/indices/realtime-sync'],
            'stock sync' => ['/admin/v2/stocks/eodhd-sync'],
            'ticker test' => ['/admin/tests/tickers'],
            'exchange test' => ['/admin/tests/exchanges'],
            'exchange reload' => ['/admin/data/exchanges/reload'],
            'trading time repair' => ['/admin/data/health/stock-trading-times/repair'],
            'EOD sync' => ['/admin/data/end-of-day/sync'],
            'legacy index sync' => ['/admin/data/indices/sync'],
            'legacy index history' => ['/admin/data/indices/historical/sync'],
            'EOD repair' => ['/admin/data/repair/end-of-day'],
            'stock EOD repair' => ['/admin/data/repair/end-of-day/{holding}'],
            'EOD history' => ['/admin/watchlist/holdings/historical-prices/ensure'],
            'bounded history' => ['/admin/watchlist/holdings/historical-price-rows'],
        ];
    }

    public function test_paid_schedules_do_not_run_with_the_shipped_policy(): void
    {
        $events = collect(Schedule::events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'indices:')
            || str_contains($event->command ?? '', 'indices-data:') || str_contains($event->command ?? '', 'end-of-day-data:'));
        $this->assertCount(4, $events);

        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass(app()));
        }

        Http::assertNothingSent();
    }
}
