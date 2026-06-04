<?php

namespace Tests\Unit;

use App\Services\EodhdApiUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EodhdApiUsageTest extends TestCase
{
    public function test_it_reports_remaining_hourly_and_daily_calls(): void
    {
        Cache::flush();
        config([
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 1195,
        ]);
        $this->travelTo(Carbon::parse('2026-06-04 13:25:00', 'Europe/Vienna'));

        $usage = app(EodhdApiUsage::class);
        $payload = $usage->payload();

        $this->assertSame(0, $payload['hour']['used']);
        $this->assertSame(1000, $payload['hour']['remaining']);
        $this->assertSame(1195, $payload['day']['used']);
        $this->assertSame(98805, $payload['day']['remaining']);
        $this->assertSame('2026-06-04T14:00:00+02:00', $payload['hour']['reset_at']);
        $this->assertSame('2026-06-05T00:00:00+02:00', $payload['day']['reset_at']);
    }

    public function test_it_counts_each_recorded_eodhd_call(): void
    {
        Cache::flush();
        config([
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 1195,
        ]);
        $this->travelTo(Carbon::parse('2026-06-04 13:25:00', 'Europe/Vienna'));

        $usage = app(EodhdApiUsage::class);
        $usage->recordCall();
        $usage->recordCall();
        $payload = $usage->payload();

        $this->assertSame(2, $payload['hour']['used']);
        $this->assertSame(998, $payload['hour']['remaining']);
        $this->assertSame(1197, $payload['day']['used']);
        $this->assertSame(98803, $payload['day']['remaining']);
    }
}
