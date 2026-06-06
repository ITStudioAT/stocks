<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'detail_code', 'name', 'country', 'currency', 'timezone', 'operating_mic', 'trading_hours', 'holidays', 'raw_exchange', 'raw_details', 'synced_at'])]
class EodhdExchange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_hours' => 'array',
            'holidays' => 'array',
            'raw_exchange' => 'array',
            'raw_details' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
