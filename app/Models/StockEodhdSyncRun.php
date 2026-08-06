<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'status', 'stage', 'intraday_reload_run_id', 'date_from', 'date_to', 'eod_requested_count', 'eod_stored_count', 'eod_skipped_count', 'eod_failed_count', 'intraday_total_count', 'intraday_processed_count', 'intraday_stored_count', 'intraday_success_count', 'intraday_failed_count', 'current', 'steps', 'message', 'error', 'started_at', 'finished_at'])]
class StockEodhdSyncRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'stage' => 'eod',
        'eod_requested_count' => 0,
        'eod_stored_count' => 0,
        'eod_skipped_count' => 0,
        'eod_failed_count' => 0,
        'intraday_total_count' => 0,
        'intraday_processed_count' => 0,
        'intraday_stored_count' => 0,
        'intraday_success_count' => 0,
        'intraday_failed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'eod_requested_count' => 'integer',
            'eod_stored_count' => 'integer',
            'eod_skipped_count' => 'integer',
            'eod_failed_count' => 'integer',
            'intraday_total_count' => 'integer',
            'intraday_processed_count' => 'integer',
            'intraday_stored_count' => 'integer',
            'intraday_success_count' => 'integer',
            'intraday_failed_count' => 'integer',
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
