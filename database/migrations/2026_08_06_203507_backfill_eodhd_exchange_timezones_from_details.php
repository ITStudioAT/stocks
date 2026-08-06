<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('eodhd_exchanges')
            ->whereNull('timezone')
            ->whereNotNull('raw_details')
            ->orderBy('id')
            ->chunkById(100, function ($exchanges): void {
                foreach ($exchanges as $exchange) {
                    $details = json_decode((string) $exchange->raw_details, true);
                    $timezone = is_array($details) ? ($details['Timezone'] ?? $details['timezone'] ?? null) : null;

                    if (! is_string($timezone) || trim($timezone) === '') {
                        continue;
                    }

                    DB::table('eodhd_exchanges')
                        ->where('id', $exchange->id)
                        ->update(['timezone' => trim($timezone)]);
                }
            });
    }
};
