<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'status', 'stage', 'total_indices', 'processed_indices', 'date_from', 'date_to', 'eod_missing_count', 'eod_synced_count', 'intraday_missing_count', 'intraday_synced_count', 'unsupported_intraday_count', 'failed_count', 'current', 'steps', 'index_progress', 'summary', 'message', 'error', 'started_at', 'finished_at'])]
class IndexEodhdSyncRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'stage' => 'check_indices',
        'total_indices' => 0,
        'processed_indices' => 0,
        'eod_missing_count' => 0,
        'eod_synced_count' => 0,
        'intraday_missing_count' => 0,
        'intraday_synced_count' => 0,
        'unsupported_intraday_count' => 0,
        'failed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'total_indices' => 'integer',
            'processed_indices' => 'integer',
            'eod_missing_count' => 'integer',
            'eod_synced_count' => 'integer',
            'intraday_missing_count' => 'integer',
            'intraday_synced_count' => 'integer',
            'unsupported_intraday_count' => 'integer',
            'failed_count' => 'integer',
            'steps' => 'array',
            'index_progress' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
