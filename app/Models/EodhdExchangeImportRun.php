<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'status', 'total_count', 'processed_count', 'success_count', 'failed_count', 'current', 'started_at', 'finished_at', 'error_summary'])]
class EodhdExchangeImportRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'total_count' => 0,
        'processed_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'success_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'error_summary' => 'array',
        ];
    }
}
