<?php

namespace App\Jobs;

use App\Models\StockHolding;
use App\Services\EodhdMarketData;
use App\Services\HistoricalSessionStartPriceFetchStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchHistoricalSessionPrices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /**
     * @param  array<int, int>  $stockHoldingIds
     * @param  array{timezone: string, today_date: string, today_open: string, today_close: string, previous_date: string, previous_open: string, previous_close: string, two_ago_date: string, two_ago_open: string, two_ago_close: string}  $session
     */
    public function __construct(
        public string $exchangeCode,
        public array $stockHoldingIds,
        public array $session,
    ) {}

    public function handle(
        EodhdMarketData $eodhdMarketData,
        HistoricalSessionStartPriceFetchStatus $fetchStatus,
    ): void {
        $windows = $this->windows();

        StockHolding::query()
            ->whereIn('id', $this->stockHoldingIds)
            ->orderBy('id')
            ->get()
            ->each(function (StockHolding $holding) use ($eodhdMarketData, $fetchStatus, $windows): void {
                $this->markRunning($fetchStatus, $holding, $windows);

                $todayStartQuote = $eodhdMarketData->intradayStartQuote(
                    $holding,
                    $windows['today_start']['from'],
                    $windows['today_start']['until'],
                );
                $todayEndQuote = $this->isAfterTodayClose()
                    ? $eodhdMarketData->dailyCloseQuote(
                        $holding,
                        Carbon::parse($this->session['today_date'], $this->session['timezone']),
                        $windows['today_end']['from'],
                    )
                    : null;
                $previousEndQuote = $eodhdMarketData->dailyCloseQuote(
                    $holding,
                    Carbon::parse($this->session['previous_date'], $this->session['timezone']),
                    $windows['previous_end']['from'],
                );
                $twoAgoEndQuote = $eodhdMarketData->dailyCloseQuote(
                    $holding,
                    Carbon::parse($this->session['two_ago_date'], $this->session['timezone']),
                    $windows['two_ago_end']['from'],
                );

                if (! $todayStartQuote || ! $previousEndQuote || ! $twoAgoEndQuote || ($this->isAfterTodayClose() && ! $todayEndQuote)) {
                    $this->markFailed($fetchStatus, $holding, $windows);

                    return;
                }

                DB::transaction(function () use ($eodhdMarketData, $holding, $todayStartQuote, $todayEndQuote, $previousEndQuote, $twoAgoEndQuote): void {
                    $eodhdMarketData->storeHistoricalQuote($holding, $todayStartQuote);
                    $eodhdMarketData->storeHistoricalQuote($holding, $previousEndQuote);
                    $eodhdMarketData->storeHistoricalQuote($holding, $twoAgoEndQuote);

                    if ($todayEndQuote) {
                        $eodhdMarketData->storeHistoricalQuote($holding, $todayEndQuote);
                    }

                    $holding->update([
                        'start_price' => $todayStartQuote->quote->price,
                        'end_price' => $todayEndQuote?->quote->price,
                        'end_price_24' => $previousEndQuote->quote->price,
                        'end_price_48' => $twoAgoEndQuote->quote->price,
                    ]);
                });

                $this->markFinished($fetchStatus, $holding, $windows);
            });
    }

    /**
     * @return array<string, array{from: Carbon, until: Carbon, type: string}>
     */
    private function windows(): array
    {
        return [
            'today_start' => [
                'from' => Carbon::parse($this->session['today_open'])->utc(),
                'until' => Carbon::parse($this->session['today_close'])->utc(),
                'type' => 'start',
            ],
            'today_end' => [
                'from' => Carbon::parse($this->session['today_close'])->utc(),
                'until' => Carbon::parse($this->session['today_close'])->utc()->addDay(),
                'type' => 'end',
            ],
            'previous_end' => [
                'from' => Carbon::parse($this->session['previous_close'])->utc(),
                'until' => Carbon::parse($this->session['today_open'])->utc(),
                'type' => 'end',
            ],
            'two_ago_end' => [
                'from' => Carbon::parse($this->session['two_ago_close'])->utc(),
                'until' => Carbon::parse($this->session['previous_open'])->utc(),
                'type' => 'end',
            ],
        ];
    }

    private function isAfterTodayClose(): bool
    {
        return now()->greaterThanOrEqualTo(Carbon::parse($this->session['today_close']));
    }

    /**
     * @param  array<string, array{from: Carbon, until: Carbon, type: string}>  $windows
     */
    private function markRunning(HistoricalSessionStartPriceFetchStatus $fetchStatus, StockHolding $holding, array $windows): void
    {
        foreach ($windows as $window) {
            if (! $this->shouldTrackWindow($window)) {
                continue;
            }

            $fetchStatus->running($holding->id, $window['from'], $window['until'], $window['type']);
        }
    }

    /**
     * @param  array<string, array{from: Carbon, until: Carbon, type: string}>  $windows
     */
    private function markFinished(HistoricalSessionStartPriceFetchStatus $fetchStatus, StockHolding $holding, array $windows): void
    {
        foreach ($windows as $window) {
            if (! $this->shouldTrackWindow($window)) {
                continue;
            }

            $fetchStatus->finished($holding->id, $window['from'], $window['until'], $window['type']);
        }
    }

    /**
     * @param  array<string, array{from: Carbon, until: Carbon, type: string}>  $windows
     */
    private function markFailed(HistoricalSessionStartPriceFetchStatus $fetchStatus, StockHolding $holding, array $windows): void
    {
        foreach ($windows as $window) {
            if (! $this->shouldTrackWindow($window)) {
                continue;
            }

            $fetchStatus->failed($holding->id, $window['from'], $window['until'], $window['type']);
        }
    }

    /**
     * @param  array{from: Carbon, until: Carbon, type: string}  $window
     */
    private function shouldTrackWindow(array $window): bool
    {
        $todayClose = Carbon::parse($this->session['today_close'])->utc();

        return ! ($window['type'] === 'end' && $window['from']->equalTo($todayClose) && ! $this->isAfterTodayClose());
    }

    public function uniqueId(): string
    {
        return $this->exchangeCode.':'.$this->session['today_date'];
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Historical session price bundle could not be fetched.', [
            'exchange_code' => $this->exchangeCode,
            'today_date' => $this->session['today_date'] ?? null,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
