<?php

namespace Tests\Unit;

use App\Models\AnalyzeResearchSetting;
use App\Models\StockAiResearch;
use App\Models\StockAiResearchSource;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\PreviewOriginalSchema;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviewOriginalSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Excluded = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens',
        'admin_login_codes', 'personal_access_tokens', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function test_allowlist_and_keys_match_the_entire_migrated_application_schema(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $schema = new PreviewOriginalSchema;
        $actualTables = array_map(fn (string $table): string => str_replace('main.', '', $table), Schema::getTableListing());
        $actualTables = array_values(array_diff($actualTables, self::Excluded));
        $this->assertCount(35, $schema->columns());
        $this->assertEqualsCanonicalizing($actualTables, array_keys($schema->columns()));
        $this->assertSame(array_keys($schema->columns()), array_keys($schema->primaryKeys()));
        $this->assertSame(array_keys($schema->columns()), array_keys($schema->foreignKeys()));

        foreach ($schema->columns() as $table => $columns) {
            $this->assertEqualsCanonicalizing(Schema::getColumnListing($table), $columns, $table);
            $this->assertCount(count(array_unique($columns)), $columns);
            $primary = array_values(array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['primary']));
            $this->assertSame($primary[0]['columns'], $schema->primaryKeys()[$table], $table);
            $nullable = array_column(Schema::getColumns($table), 'nullable', 'name');

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $expected = [
                    'columns' => $foreignKey['columns'],
                    'foreign_table' => $foreignKey['foreign_table'],
                    'foreign_columns' => $foreignKey['foreign_columns'],
                    'nullable' => ! in_array(false, array_map(fn (string $column): bool => $nullable[$column], $foreignKey['columns']), true),
                ];
                $this->assertContains($expected, $schema->foreignKeys()[$table], $table);
            }

            foreach ($schema->foreignKeys()[$table] as $reference) {
                $this->assertArrayHasKey($reference['foreign_table'], $schema->columns());
                $this->assertSame($schema->primaryKeys()[$reference['foreign_table']], $reference['foreign_columns']);
                $this->assertSame($reference['nullable'], ! in_array(false, array_map(fn (string $column): bool => $nullable[$column], $reference['columns']), true));
            }
        }
    }

    public function test_nonnullable_foreign_keys_have_a_valid_insertion_order(): void
    {
        $remaining = (new PreviewOriginalSchema)->foreignKeys();
        $inserted = [];

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $table => $references) {
                $required = array_column(array_filter($references, fn (array $reference): bool => ! $reference['nullable']), 'foreign_table');

                if (array_diff($required, $inserted) !== []) {
                    continue;
                }

                $inserted[] = $table;
                unset($remaining[$table]);
                $progress = true;
            }

            $this->assertTrue($progress, 'Nonnullable foreign keys contain an insertion cycle.');
        }

        $this->assertCount(35, $inserted);
    }

    public function test_empty_application_snapshot_is_valid_for_backup_and_original_values_are_not_modified(): void
    {
        $schema = new PreviewOriginalSchema;
        $schema->validate(array_fill_keys(array_keys($schema->columns()), []));
        $tables = $this->fixture();
        $tables['users'][0]['password'] = 'fixture-password-hash';
        $tables['users'][0]['remember_token'] = 'fixture-remember-token';
        $tables['depots'][0]['account_number'] = 'Original account';
        $tables['depot_transactions'][0]['note'] = 'Original note';
        $tables['stock_ai_researches'][0]['previous_research_id'] = $tables['stock_ai_researches'][0]['id'];
        $tables['stock_holdings'][0]['latest_quote_id'] = 1;
        $tables['stock_holdings'][0]['latest_stock_price_id'] = 1;
        $tables['stock_holdings'][0]['latest_realtime_price_id'] = 1;
        $tables['stock_realtime_prices'][0]['stock_holding_id'] = 1;
        $original = $tables;

        $schema->validate(array_reverse($tables, true));

        $this->assertSame($original, $tables);
    }

    public function test_missing_extra_and_runtime_tables_and_inexact_columns_are_rejected(): void
    {
        $fixture = $this->fixture();

        foreach (self::Excluded as $table) {
            $tables = $fixture;
            $tables[$table] = [];
            $this->assertRejected($tables);
        }

        $tables = $fixture;
        unset($tables['users']);
        $this->assertRejected($tables);
        $tables = $fixture;
        unset($tables['users'][0]['remember_token']);
        $this->assertRejected($tables);
        $tables['users'][0]['unapproved'] = null;
        $this->assertRejected($tables);
        $tables = $fixture;
        $tables['roles'] = [1 => $tables['roles'][0]];
        $this->assertRejected($tables);
        $tables = $fixture;
        $tables['roles'] = [null];
        $this->assertRejected($tables);
    }

    public function test_required_columns_reject_null_and_values_must_be_finite_scalars(): void
    {
        $fixture = $this->fixture();

        foreach ((new PreviewOriginalSchema)->columns() as $table => $columns) {
            foreach (Schema::getColumns($table) as $column) {
                if ($column['nullable']) {
                    continue;
                }

                $tables = $fixture;
                $tables[$table][0][$column['name']] = null;
                $this->assertRejected($tables);
            }
        }

        foreach ([[], new \stdClass, INF, -INF, NAN] as $value) {
            $tables = $fixture;
            $tables['users'][0]['first_name'] = $value;
            $this->assertRejected($tables);
        }
    }

    #[DataProvider('invalidNumericIdentities')]
    public function test_invalid_numeric_primary_and_foreign_keys_are_rejected(mixed $value): void
    {
        $tables = $this->fixture();
        $tables['users'][0]['id'] = $value;
        $this->assertRejected($tables);
        $tables = $this->fixture();
        $tables['model_has_roles'][0]['model_id'] = $value;
        $this->assertRejected($tables);
    }

    public static function invalidNumericIdentities(): array
    {
        return [[0], [-1], [1.0], [true], [false], [null], [''], ['01'], ['1.0'], ['1e0'], ['+1'], ["1\n"], ['18446744073709551616']];
    }

    public function test_string_primary_keys_reject_invalid_types_and_respect_column_length(): void
    {
        foreach ([1, true, '', str_repeat('x', 256), "invalid\0identity"] as $value) {
            $tables = $this->fixture();
            $tables['stock_ai_researches'][0]['id'] = $value;
            $this->assertRejected($tables);
        }

        $tables = $this->fixture();
        $tables['agent_conversation_messages'][0]['id'] = str_repeat('x', 37);
        $this->assertRejected($tables);
    }

    public function test_numeric_and_composite_duplicate_keys_are_rejected_without_merging_users(): void
    {
        foreach (['users', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $tables = $this->fixture();
            $duplicate = $tables[$table][0];

            foreach ((new PreviewOriginalSchema)->primaryKeys()[$table] as $column) {
                $duplicate[$column] = (string) $duplicate[$column];
            }

            $tables[$table][] = $duplicate;
            $this->assertRejected($tables);
        }
    }

    public function test_unsigned_bigint_ids_preserve_precision_across_numeric_and_string_references(): void
    {
        $tables = $this->fixture();
        $tables['users'][0]['id'] = '18446744073709551615';

        foreach ((new PreviewOriginalSchema)->foreignKeys() as $table => $references) {
            foreach ($references as $reference) {
                if ($reference['foreign_table'] === 'users') {
                    $tables[$table][0][$reference['columns'][0]] = '18446744073709551615';
                }
            }
        }

        (new PreviewOriginalSchema)->validate($tables);
        $this->assertSame('18446744073709551615', $tables['users'][0]['id']);
    }

    public function test_every_physical_and_logical_foreign_key_requires_a_present_row(): void
    {
        $fixture = $this->fixture();

        foreach ((new PreviewOriginalSchema)->foreignKeys() as $table => $references) {
            foreach ($references as $reference) {
                $tables = $fixture;
                $foreignId = $tables[$reference['foreign_table']][0][$reference['foreign_columns'][0]];
                $tables[$table][0][$reference['columns'][0]] = is_int($foreignId) ? 999 : 'missing-original-row';
                $this->assertRejected($tables);
            }
        }
    }

    public function test_permission_polymorphic_references_only_allow_the_original_user_model(): void
    {
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            $tables = $this->fixture();
            $tables[$table][0]['model_type'] = 'App\\Models\\StockHolding';
            $this->assertRejected($tables);
        }
    }

    public function test_user_research_sources_and_previous_results_cannot_cross_user_or_holding_boundaries(): void
    {
        $fixture = $this->fixture();
        $secondUser = $fixture['users'][0];
        $secondUser['id'] = 2;
        $fixture['users'][] = $secondUser;
        $secondHolding = $fixture['stock_holdings'][0];
        $secondHolding['id'] = 2;
        $fixture['stock_holdings'][] = $secondHolding;

        foreach (['user_id', 'stock_holding_id'] as $column) {
            $tables = $fixture;
            $tables['stock_ai_research_sources'][0][$column] = 2;
            $this->assertRejected($tables);
            $tables = $fixture;
            $previous = $tables['stock_ai_researches'][0];
            $previous['id'] = 'previous-research';
            $previous[$column] = 2;
            $tables['stock_ai_researches'][] = $previous;
            $tables['stock_ai_researches'][0]['previous_research_id'] = 'previous-research';
            $this->assertRejected($tables);
        }
    }

    public function test_conversation_messages_cannot_cross_user_ownership(): void
    {
        $tables = $this->fixture();
        $tables['agent_conversations'][0]['user_id'] = 1;
        $tables['agent_conversation_messages'][0]['user_id'] = null;
        $this->assertRejected($tables);

        $second = $tables['users'][0];
        $second['id'] = 2;
        $tables['users'][] = $second;
        $tables['agent_conversation_messages'][0]['user_id'] = 2;
        $this->assertRejected($tables);
    }

    public function test_two_original_users_keep_separate_research_settings_sources_and_credentials(): void
    {
        $holding = StockHolding::factory()->create();
        $users = User::factory()->count(2)->create();
        $researchIds = [];

        foreach ($users as $index => $user) {
            $research = StockAiResearch::factory()->recycle($user)->recycle($holding)->create(['summary' => 'Owner '.($index + 1)]);
            StockAiResearchSource::factory()->recycle($user)->recycle($holding)->recycle($research)->create();
            AnalyzeResearchSetting::factory()->recycle($user)->create(['settings' => ['rows' => 100 + $index]]);
            $researchIds[$user->id] = $research->id;
        }

        $tables = [];

        foreach ((new PreviewOriginalSchema)->columns() as $table => $columns) {
            $tables[$table] = DB::table($table)->get($columns)->map(fn (object $row): array => (array) $row)->all();
        }

        $original = $tables;
        (new PreviewOriginalSchema)->validate($tables);
        $this->assertSame($original, $tables);

        foreach ($users as $user) {
            $this->assertSame([$researchIds[$user->id]], $user->stockAiResearches()->pluck('id')->all());
            $research = array_values(array_filter($tables['stock_ai_researches'], fn (array $row): bool => $row['user_id'] === $user->id));
            $sources = array_values(array_filter($tables['stock_ai_research_sources'], fn (array $row): bool => $row['user_id'] === $user->id));
            $settings = array_values(array_filter($tables['analyze_research_settings'], fn (array $row): bool => $row['user_id'] === $user->id));
            $this->assertCount(1, $research);
            $this->assertCount(1, $sources);
            $this->assertCount(1, $settings);
            $this->assertSame($researchIds[$user->id], $sources[0]['stock_ai_research_id']);
            $originalUser = array_values(array_filter($tables['users'], fn (array $row): bool => $row['id'] === $user->id))[0];
            $this->assertTrue(hash_equals($user->getRawOriginal('password'), $originalUser['password']));
        }
    }

    /** @return array<string, list<array<string, scalar|null>>> */
    private function fixture(): array
    {
        $schema = new PreviewOriginalSchema;
        $tables = [];

        foreach ($schema->columns() as $table => $columns) {
            $row = [];

            foreach (Schema::getColumns($table) as $column) {
                $row[$column['name']] = $column['nullable'] ? null : (in_array($column['type_name'], ['integer', 'tinyint', 'numeric'], true) ? 1 : 'fixture');

                if ($column['name'] === 'id' && $column['type_name'] === 'varchar') {
                    $row['id'] = 'row-'.$table;
                }
            }

            if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)) {
                $row['model_type'] = User::class;
            }

            $tables[$table] = [$row];
        }

        foreach ($schema->foreignKeys() as $table => $references) {
            foreach ($references as $reference) {
                if (! $reference['nullable']) {
                    $tables[$table][0][$reference['columns'][0]] = $tables[$reference['foreign_table']][0][$reference['foreign_columns'][0]];
                }
            }
        }

        return $tables;
    }

    private function assertRejected(array $tables): void
    {
        try {
            (new PreviewOriginalSchema)->validate($tables);
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Invalid original snapshot was accepted.');
    }
}
