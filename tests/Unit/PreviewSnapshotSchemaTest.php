<?php

namespace Tests\Unit;

use App\Services\PreviewSnapshotPolicy;
use App\Services\PreviewSnapshotSchema;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviewSnapshotSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allowlist_matches_every_column_and_foreign_key_in_the_migrated_schema(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $columns = (new PreviewSnapshotSchema)->columns();
        $this->assertEqualsCanonicalizing(PreviewSnapshotPolicy::Tables, array_keys($columns));

        foreach ($columns as $table => $allowed) {
            $this->assertEqualsCanonicalizing(Schema::getColumnListing($table), $allowed, $table);
            $this->assertCount(count(array_unique($allowed)), $allowed);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertArrayHasKey($foreignKey['foreign_table'], $columns);
                $tables = $this->fixture();
                $tables[$table][0][$foreignKey['columns'][0]] = 999;
                $this->assertRejected($tables);
            }
        }
    }

    public function test_complete_dataset_with_price_cycles_and_original_business_values_is_accepted(): void
    {
        $tables = $this->fixture();
        $tables['depots'][0]['name'] = 'Original depot';
        $tables['depots'][0]['account_number'] = 'Original account';
        $tables['depot_transactions'][0]['note'] = 'Original note';
        $tables['stock_holdings'][0]['id'] = '1';
        $tables['stock_prices'][0]['id'] = '18446744073709551615';
        $tables['stock_holdings'][0]['latest_stock_price_id'] = '18446744073709551615';
        $tables['stock_realtime_prices'][0]['legacy_stock_price_id'] = '18446744073709551615';
        $tables['stock_holding_intraday_prices'][0]['source_stock_price_id'] = '18446744073709551615';
        $original = $tables;

        (new PreviewSnapshotSchema)->validate($tables);

        $this->assertSame($original, $tables);
    }

    public function test_all_tables_may_be_empty_and_order_is_irrelevant(): void
    {
        $tables = array_fill_keys(array_reverse(PreviewSnapshotPolicy::Tables), []);
        (new PreviewSnapshotSchema)->validate($tables);
        $this->addToAssertionCount(1);
    }

    public function test_missing_and_extra_tables_or_columns_and_non_list_rows_are_rejected(): void
    {
        $valid = $this->fixture();
        $cases = [];
        $tables = $valid;
        unset($tables['stock_prices']);
        $cases[] = $tables;
        $tables = $valid;
        $tables['users'] = [];
        $cases[] = $tables;
        $tables = $valid;
        unset($tables['depots'][0]['description']);
        $cases[] = $tables;
        $tables = $valid;
        $tables['depots'][0]['password'] = 'unexpected';
        $cases[] = $tables;
        $tables = $valid;
        unset($tables['depots'][0]['description']);
        $tables['depots'][0]['unexpected'] = null;
        $cases[] = $tables;
        $tables = $valid;
        $tables['depots'] = [1 => $tables['depots'][0]];
        $cases[] = $tables;
        $tables = $valid;
        $tables['depots'] = 'invalid';
        $cases[] = $tables;
        $tables = $valid;
        $tables['depots'] = [null];
        $cases[] = $tables;

        foreach ($cases as $tables) {
            $this->assertRejected($tables);
        }
    }

    #[DataProvider('invalidIdentities')]
    public function test_invalid_primary_and_foreign_identities_are_rejected(mixed $id): void
    {
        $tables = $this->fixture();
        $tables['depots'][0]['id'] = $id;
        $this->assertRejected($tables);
        $tables = $this->fixture();
        $tables['depot_transactions'][0]['depot_id'] = $id;
        $this->assertRejected($tables);
    }

    public static function invalidIdentities(): array
    {
        return [
            [0], [-1], [1.0], [true], [false], [null], [''], ['01'],
            ['1.0'], ['1e0'], ['+1'], ["1\n"], ['18446744073709551616'],
        ];
    }

    public function test_duplicate_numeric_and_string_identities_are_rejected(): void
    {
        $tables = $this->fixture();
        $duplicate = $tables['depots'][0];
        $duplicate['id'] = '1';
        $tables['depots'][] = $duplicate;

        $this->assertRejected($tables);
    }

    public function test_nullability_matches_the_migrated_schema(): void
    {
        $fixture = $this->fixture();

        foreach ((new PreviewSnapshotSchema)->columns() as $table => $columns) {
            foreach (Schema::getColumns($table) as $column) {
                $tables = $fixture;
                $tables[$table][0][$column['name']] = null;

                if (! $column['nullable']) {
                    $this->assertRejected($tables);

                    continue;
                }

                if ($table === 'stock_realtime_prices' && $column['name'] === 'stock_holding_id') {
                    $tables['stock_holdings'][0]['latest_realtime_price_id'] = null;
                }

                (new PreviewSnapshotSchema)->validate($tables);
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_scalar_finite_values_are_accepted(): void
    {
        foreach ([[], new \stdClass, INF, -INF, NAN] as $value) {
            $tables = $this->fixture();
            $tables['depots'][0]['description'] = $value;
            $this->assertRejected($tables);
        }
    }

    public function test_logical_intraday_source_price_reference_cannot_be_dangling(): void
    {
        $tables = $this->fixture();
        $tables['stock_holding_intraday_prices'][0]['source_stock_price_id'] = 999;
        $this->assertRejected($tables);
    }

    public function test_latest_quote_and_realtime_price_must_belong_to_the_holding(): void
    {
        foreach (['stock_price_quotes', 'stock_realtime_prices'] as $table) {
            $tables = $this->fixture();
            $secondHolding = $tables['stock_holdings'][0];
            $secondHolding['id'] = 2;
            $secondHolding['latest_quote_id'] = null;
            $secondHolding['latest_stock_price_id'] = null;
            $secondHolding['latest_realtime_price_id'] = null;
            $tables['stock_holdings'][] = $secondHolding;
            $tables[$table][0]['stock_holding_id'] = 2;
            $this->assertRejected($tables);
        }

        $tables = $this->fixture();
        $tables['stock_realtime_prices'][0]['stock_holding_id'] = null;
        $this->assertRejected($tables);
    }

    /** @return array<string, list<array<string, scalar|null>>> */
    private function fixture(): array
    {
        $tables = [];

        foreach ((new PreviewSnapshotSchema)->columns() as $table => $columns) {
            $row = [];

            foreach (Schema::getColumns($table) as $column) {
                $row[$column['name']] = $column['nullable'] ? null : 1;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $row[$foreignKey['columns'][0]] = 1;
            }

            $tables[$table] = [$row];
        }

        $tables['stock_holding_intraday_prices'][0]['source_stock_price_id'] = 1;

        return $tables;
    }

    private function assertRejected(array $tables): void
    {
        try {
            (new PreviewSnapshotSchema)->validate($tables);
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Invalid snapshot was accepted.');
    }
}
