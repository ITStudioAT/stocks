<?php

namespace App\Models;

use Database\Factories\WebsiteAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'client_id',
    'user_id',
    'url',
    'host',
    'status',
    'analysis_step',
    'pages_count',
    'assets_count',
    'reachability_checked_count',
    'reachability_total_count',
    'result_path',
    'error_message',
    'started_at',
    'completed_at',
])]
class WebsiteAnalysis extends Model
{
    /** @use HasFactory<WebsiteAnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
