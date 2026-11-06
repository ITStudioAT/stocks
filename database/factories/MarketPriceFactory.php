<?php

namespace Database\Factories;

use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Services\MarketIdentityRegistry;
use App\Services\MarketPriceLegacyImporter;
use App\Services\MarketPriceStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketPrice> */
class MarketPriceFactory extends Factory
{
    public function definition(): array
    {
        $holding = StockHolding::factory()->create(['isin' => 'US'.fake()->unique()->numerify('##########'),
            'symbol' => strtoupper(fake()->lexify('????')), 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS', 'currency' => 'USD']);
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $data = app(MarketPriceLegacyImporter::class)->attributes($holding, ['source_key' => 'fixture', 'source_name' => 'Test fixture',
            'as_of' => now()->utc()->toDateTimeString(), 'fetched_at' => now()->utc()->toDateTimeString(),
            'price' => '109.9912345678', 'currency' => 'USD']);

        return [...app(MarketPriceStore::class)->prepare($listing->id, $data), 'revision' => 1, 'is_current' => true];
    }
}
