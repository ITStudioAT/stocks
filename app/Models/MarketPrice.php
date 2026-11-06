<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketPrice extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'current_key'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'raw_payload' => 'array', 'provenance' => 'array', 'validation_errors' => 'array'];
    }
}
