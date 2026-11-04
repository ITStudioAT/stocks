<?php

namespace App\Services;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FreeInstrumentSearch
{
    public function __construct(private XetraInstrumentCatalog $catalog, private KnownInstrumentMetadataCorrections $corrections) {}

    /** @return array{results: array<int, array<string, mixed>>, source: string, status: string, message: string, catalog_as_of: ?string} */
    public function search(string $query): array
    {
        $query = trim($query);
        $catalog = $this->catalog->search($query);
        $saved = collect([StockHolding::class, IndexWatchItem::class])->flatMap(function (string $model) use ($query): array {
            $needle = Str::upper($query);
            if (str_ends_with($needle, '.INDX')) {
                $needle = Str::beforeLast($needle, '.INDX');
            }
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';

            return $model::query()->where(function (Builder $builder) use ($pattern): void {
                foreach (['symbol', 'name', 'isin', 'wkn'] as $field) {
                    $builder->orWhereRaw("UPPER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            })->orderByRaw('CASE WHEN UPPER(symbol) = ? OR UPPER(isin) = ? OR UPPER(wkn) = ? THEN 0 ELSE 1 END', [$needle, $needle, $needle])
                ->limit(50)->get(['name', 'isin', 'wkn', 'symbol', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency'])
                ->map(fn (StockHolding|IndexWatchItem $instrument): array => [...$instrument->toArray(), 'valor' => null,
                    'source_key' => 'stored_instrument', 'source_name' => 'Saved instrument metadata',
                    'source_url' => null, 'source_as_of' => null])->all();
        });
        $known = collect($this->corrections->searchFallbacksForQuery($query))->map(fn (array $instrument): array => [
            ...$instrument, 'source_key' => 'verified_instrument', 'source_name' => 'Verified instrument mapping',
            'source_url' => null, 'source_as_of' => null,
        ]);
        $needle = Str::upper($query);
        $results = collect($catalog['instruments'])->merge($saved)->merge($known)
            ->map(fn (array $instrument): array => $this->corrections->apply($instrument))
            ->unique(fn (array $instrument): string => implode('|', array_map(
                fn (string $field): string => (string) ($instrument[$field] ?? ''), ['isin', 'symbol', 'exchange', 'mic_code', 'currency'],
            )))
            ->sortBy(fn (array $instrument): int => in_array($needle, [$instrument['isin'] ?? null, $instrument['symbol'] ?? null, $instrument['wkn'] ?? null], true) ? 0 : 1)
            ->take(10)->values()->all();
        $message = 'Free search covers Xetra shares/ETFs and saved or verified instruments. EODHD is used if no suitable result is available.';
        if ($catalog['status'] === 'unavailable') {
            $message = 'Xetra catalogue is currently unavailable. Saved or verified instruments are searched first, then EODHD if needed.';
        }
        if ($catalog['status'] === 'stale') {
            $message = 'Xetra catalogue refresh failed; dated cached reference data and saved instruments are shown.';
        }

        return ['results' => $results, 'source' => 'free', 'status' => $catalog['status'], 'message' => $message, 'catalog_as_of' => $catalog['as_of']];
    }
}
