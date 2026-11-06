<?php

namespace Database\Seeders;

use App\Models\StockHolding;
use App\Services\MarketIdentityRegistry;
use Illuminate\Database\Seeder;

class MarketPriceSeeder extends Seeder
{
    public function run(): void
    {
        StockHolding::query()->each(fn (StockHolding $holding) => app(MarketIdentityRegistry::class)->listing($holding));
    }
}
