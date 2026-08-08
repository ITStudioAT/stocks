<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\User;
use App\Services\CloudwaysDatabaseSync;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCloudwaysSyncTest extends TestCase
{
    use RefreshDatabase;

    private ?string $cloudwaysDatabasePath = null;

    protected function tearDown(): void
    {
        DB::purge('cloudways_testing');
        DB::purge('cloudways_target_testing');

        if ($this->cloudwaysDatabasePath && file_exists($this->cloudwaysDatabasePath)) {
            unlink($this->cloudwaysDatabasePath);
        }

        parent::tearDown();
    }

    public function test_cloudways_sync_configuration_includes_v2_data_and_excludes_operational_tables(): void
    {
        $tables = config('services.cloudways.sync_tables');

        $this->assertContains('index_watch_item_realtime_prices', $tables);
        $this->assertContains('stock_realtime_prices', $tables);
        $this->assertNotContains('index_eodhd_sync_runs', $tables);
        $this->assertNotContains('stock_eodhd_sync_runs', $tables);
        $this->assertNotContains('app_configs', $tables);
        $this->assertNotContains('jobs', $tables);
        $this->assertNotContains('migrations', $tables);
        $this->assertNotContains('sessions', $tables);
    }

    public function test_cloudways_execution_status_is_stored_and_remembered(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');

        DB::table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);
        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);

        $this->actingAs($this->superAdminUser())
            ->getJson('/admin/cloudways/status')
            ->assertOk()
            ->assertJsonPath('execution_status.last_checked_at', null)
            ->assertJsonPath('execution_status.last_synced_at', null)
            ->assertJsonPath('execution_status.timezone', 'Europe/Vienna');

        $this->travelTo(Carbon::parse('2026-08-08 12:00:00', 'Europe/Vienna'));

        $this->getJson('/admin/cloudways/check')
            ->assertOk()
            ->assertJsonPath('comparison.checked_at', '2026-08-08T12:00:00+02:00');

        $this->getJson('/admin/cloudways/status')
            ->assertOk()
            ->assertJsonPath('execution_status.last_checked_at', '2026-08-08T12:00:00+02:00')
            ->assertJsonPath('execution_status.last_synced_at', null);

        $this->travelTo(Carbon::parse('2026-08-08 13:00:00', 'Europe/Vienna'));

        $this->postJson('/admin/cloudways/sync', [
            'tables' => ['cloud_items'],
        ])
            ->assertOk()
            ->assertJsonPath('sync.synced_at', '2026-08-08T13:00:00+02:00');

        $this->getJson('/admin/cloudways/status')
            ->assertOk()
            ->assertJsonPath('execution_status.last_checked_at', '2026-08-08T12:00:00+02:00')
            ->assertJsonPath('execution_status.last_synced_at', '2026-08-08T13:00:00+02:00');

        $this->assertSame(
            ['last_executed_at' => '2026-08-08T12:00:00+02:00'],
            AppConfig::query()->where('key', 'cloudways.database_check')->firstOrFail()->value,
        );
        $this->assertSame(
            ['last_executed_at' => '2026-08-08T13:00:00+02:00'],
            AppConfig::query()->where('key', 'cloudways.database_sync')->firstOrFail()->value,
        );
    }

    public function test_super_admin_can_compare_cloudways_and_local_table_contents(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');
        $this->createCompatibleItemsTable();
        $this->createCompatibleItemsTable('cloudways_testing');

        DB::table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);
        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);
        DB::table('compatible_items')->insert([
            'id' => 1,
            'name' => 'Local content',
        ]);
        DB::connection('cloudways_testing')->table('compatible_items')->insert([
            'id' => 1,
            'name' => 'Cloudways content',
        ]);
        Schema::create('local_only_items', function (Blueprint $table): void {
            $table->id();
        });
        Schema::connection('cloudways_testing')->create('remote_only_items', function (Blueprint $table): void {
            $table->id();
        });

        $response = $this->actingAs($this->superAdminUser())
            ->getJson('/admin/cloudways/check')
            ->assertOk()
            ->assertJsonPath('comparison.identical_tables', 1);

        $tables = collect($response->json('comparison.tables'))->keyBy('name');

        $this->assertSame('identical', $tables->get('cloud_items')['status']);
        $this->assertSame('Contents match.', $tables->get('cloud_items')['message']);
        $this->assertSame('different', $tables->get('compatible_items')['status']);
        $this->assertNotSame(
            $tables->get('compatible_items')['cloudways_hash'],
            $tables->get('compatible_items')['local_hash'],
        );
        $this->assertSame('missing_local', $tables->get('remote_only_items')['status']);
        $this->assertSame('missing_cloudways', $tables->get('local_only_items')['status']);
    }

    public function test_json_content_is_compared_semantically(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createJsonItemsTable();
        $this->createJsonItemsTable('cloudways_testing');

        DB::table('json_items')->insert([
            'id' => 1,
            'payload' => '{"active":true,"empty":{},"items":[{"name":"first","value":1},{"name":"second","value":2}],"metadata":{"a":1,"b":2}}',
        ]);
        DB::connection('cloudways_testing')->table('json_items')->insert([
            'id' => 1,
            'payload' => '{"metadata":{"b":2,"a":1},"items":[{"value":1,"name":"first"},{"value":2,"name":"second"}],"empty":{},"active":true}',
        ]);

        $response = $this->actingAs($this->superAdminUser())
            ->getJson('/admin/cloudways/check')
            ->assertOk();

        $jsonTable = collect($response->json('comparison.tables'))->firstWhere('name', 'json_items');

        $this->assertSame('identical', $jsonTable['status']);
        $this->assertSame('Contents match.', $jsonTable['message']);
        $this->assertSame($jsonTable['cloudways_hash'], $jsonTable['local_hash']);

        DB::table('json_items')->where('id', 1)->update([
            'payload' => '{"active":true,"empty":{},"items":[{"name":"second","value":2},{"name":"first","value":1}],"metadata":{"a":1,"b":2}}',
        ]);

        $response = $this->getJson('/admin/cloudways/check')->assertOk();
        $jsonTable = collect($response->json('comparison.tables'))->firstWhere('name', 'json_items');

        $this->assertSame('different', $jsonTable['status']);
        $this->assertNotSame($jsonTable['cloudways_hash'], $jsonTable['local_hash']);
    }

    public function test_regular_admin_cannot_compare_cloudways_and_local_table_contents(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson('/admin/cloudways/status')
            ->assertForbidden();

        $this
            ->getJson('/admin/cloudways/check')
            ->assertForbidden();
    }

    public function test_super_admin_can_stream_detailed_cloudways_comparison_progress(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');

        DB::table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);
        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Same item',
            'quantity' => 10,
        ]);

        $response = $this->actingAs($this->superAdminUser())
            ->get('/admin/cloudways/check', [
                'Accept' => 'application/x-ndjson',
            ])
            ->assertOk();

        $events = collect(explode("\n", trim($response->streamedContent())))
            ->map(fn (string $line): array => json_decode($line, true));

        $this->assertSame('progress', $events->first()['type']);
        $this->assertSame('checking', $events->first()['progress']['phase']);
        $this->assertSame('cloud_items', $events->first()['progress']['table']);
        $this->assertSame(1, $events->first()['progress']['position']);
        $this->assertGreaterThanOrEqual(1, $events->first()['progress']['total']);
        $this->assertSame('table', $events->get(1)['type']);
        $this->assertSame(1, $events->get(1)['completed']);
        $this->assertSame($events->first()['progress']['total'], $events->get(1)['total']);
        $this->assertSame('finished', $events->last()['type']);
    }

    public function test_guest_cannot_compare_cloudways_and_local_table_contents(): void
    {
        $this->getJson('/admin/cloudways/status')->assertUnauthorized();
        $this->getJson('/admin/cloudways/check')->assertUnauthorized();
    }

    public function test_super_admin_can_sync_cloudways_tables_to_local_database(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');

        DB::table('cloud_items')->insert([
            'id' => 99,
            'name' => 'Local stale item',
            'quantity' => 1,
        ]);
        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            [
                'id' => 1,
                'name' => 'Remote first item',
                'quantity' => 10,
            ],
            [
                'id' => 2,
                'name' => 'Remote second item',
                'quantity' => 20,
            ],
        ]);

        Schema::connection('cloudways_testing')->create('remote_only_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('ignored_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::connection('cloudways_testing')->create('ignored_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('ignored_items')->insert([
            'id' => 1,
            'name' => 'Keep local content',
        ]);
        DB::connection('cloudways_testing')->table('ignored_items')->insert([
            'id' => 1,
            'name' => 'Ignore Cloudways content',
        ]);

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['cloud_items', 'remote_only_items'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Synced 1 table(s) and 2 row(s) from Cloudways.')
            ->assertJsonPath('sync.synced_tables', 1)
            ->assertJsonPath('sync.rows', 2)
            ->assertJsonPath('sync.tables.0.name', 'cloud_items')
            ->assertJsonPath('sync.tables.0.rows', 2)
            ->assertJsonPath('sync.tables.0.status', 'imported')
            ->assertJsonPath('sync.tables.0.message', 'Imported cloud_items: 2 row(s), 3 column(s).')
            ->assertJsonPath('sync.skipped_tables.0', 'remote_only_items')
            ->assertJsonPath('sync.skipped_table_details.0.name', 'remote_only_items')
            ->assertJsonPath('sync.skipped_table_details.0.reason', 'missing_local_table')
            ->assertJsonPath('sync.skipped_table_details.0.message', 'Skipped remote_only_items: no matching local table.');

        $this->assertDatabaseMissing('cloud_items', [
            'id' => 99,
            'name' => 'Local stale item',
        ]);
        $this->assertDatabaseHas('cloud_items', [
            'id' => 1,
            'name' => 'Remote first item',
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('cloud_items', [
            'id' => 2,
            'name' => 'Remote second item',
            'quantity' => 20,
        ]);
        $this->assertDatabaseHas('ignored_items', [
            'id' => 1,
            'name' => 'Keep local content',
        ]);
    }

    public function test_super_admin_can_stream_cloudways_table_sync_progress(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');

        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Remote first item',
            'quantity' => 10,
        ]);

        $response = $this->actingAs($this->superAdminUser())
            ->post('/admin/cloudways/sync', [
                'tables' => ['cloud_items'],
            ], [
                'Accept' => 'application/x-ndjson',
            ])
            ->assertOk();

        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));

        $events = collect(explode("\n", trim($response->streamedContent())))
            ->map(fn (string $line): array => json_decode($line, true));

        $this->assertSame('progress', $events->first()['type']);
        $this->assertSame('planned', $events->first()['progress']['phase']);
        $this->assertSame(1, $events->first()['progress']['total']);
        $this->assertSame('clearing', $events->get(1)['progress']['phase']);
        $this->assertSame('importing', $events->get(2)['progress']['phase']);
        $this->assertSame('table', $events->get(3)['type']);
        $this->assertSame('cloud_items', $events->get(3)['table']['name']);
        $this->assertSame(1, $events->get(3)['completed']);
        $this->assertSame(1, $events->get(3)['total']);
        $this->assertSame('Imported cloud_items: 1 row(s), 3 column(s).', $events->get(3)['table']['message']);
        $this->assertSame('finished', $events->last()['type']);
        $this->assertSame('Synced 1 table(s) and 1 row(s) from Cloudways.', $events->last()['message']);
    }

    public function test_cloudways_sync_does_not_import_tables_that_are_already_identical(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');

        $item = [
            'id' => 1,
            'name' => 'Already synchronized item',
            'quantity' => 10,
        ];

        DB::table('cloud_items')->insert($item);
        DB::connection('cloudways_testing')->table('cloud_items')->insert($item);

        $deleteQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$deleteQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete from')) {
                $deleteQueries[] = $query->sql;
            }
        });

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['cloud_items'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Synced 0 table(s) and 0 row(s) from Cloudways.')
            ->assertJsonPath('sync.synced_tables', 0)
            ->assertJsonPath('sync.rows', 0)
            ->assertJsonPath('sync.skipped_tables.0', 'cloud_items')
            ->assertJsonPath('sync.skipped_table_details.0.name', 'cloud_items')
            ->assertJsonPath('sync.skipped_table_details.0.reason', 'already_identical')
            ->assertJsonPath('sync.skipped_table_details.0.message', 'Skipped cloud_items: contents already match.');

        $this->assertDatabaseHas('cloud_items', $item);
        $this->assertSame([], $deleteQueries);
    }

    public function test_cloudways_sync_imports_only_different_tables(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTable();
        $this->createCloudItemsTable('cloudways_testing');
        $this->createCompatibleItemsTable();
        $this->createCompatibleItemsTable('cloudways_testing');

        $identicalItem = [
            'id' => 1,
            'name' => 'Keep this identical item',
            'quantity' => 10,
        ];

        DB::table('cloud_items')->insert($identicalItem);
        DB::connection('cloudways_testing')->table('cloud_items')->insert($identicalItem);
        DB::table('compatible_items')->insert([
            'id' => 1,
            'name' => 'Local stale item',
        ]);
        DB::connection('cloudways_testing')->table('compatible_items')->insert([
            'id' => 2,
            'name' => 'Cloudways current item',
        ]);

        $deleteQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$deleteQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete from')) {
                $deleteQueries[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['compatible_items'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Synced 1 table(s) and 1 row(s) from Cloudways.')
            ->assertJsonPath('sync.synced_tables', 1)
            ->assertJsonPath('sync.total_tables', 1)
            ->assertJsonCount(0, 'sync.skipped_table_details')
            ->assertJsonPath('sync.tables.0.name', 'compatible_items');

        $this->assertDatabaseHas('cloud_items', $identicalItem);
        $this->assertDatabaseMissing('compatible_items', [
            'id' => 1,
            'name' => 'Local stale item',
        ]);
        $this->assertDatabaseHas('compatible_items', [
            'id' => 2,
            'name' => 'Cloudways current item',
        ]);
        $this->assertCount(1, $deleteQueries);
        $this->assertStringContainsString('compatible_items', $deleteQueries[0]);
        $this->assertStringNotContainsString('cloud_items', $deleteQueries[0]);
    }

    public function test_cloudways_sync_table_selection_must_be_an_array_of_distinct_strings(): void
    {
        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tables');

        $this->postJson('/admin/cloudways/sync', [
            'tables' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tables');

        $this->postJson('/admin/cloudways/sync', [
            'tables' => ['not_configured'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tables.0');

        $this->postJson('/admin/cloudways/sync', [
            'tables' => 'stock_holdings',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tables');

        $this->postJson('/admin/cloudways/sync', [
            'tables' => ['stock_holdings', 'stock_holdings'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tables.1');
    }

    public function test_cloudways_sync_skips_tables_with_missing_required_target_columns(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudItemsTableWithRequiredLocalColumn();
        $this->createCloudItemsTable('cloudways_testing');
        $this->createCompatibleItemsTable();
        $this->createCompatibleItemsTable('cloudways_testing');

        DB::table('cloud_items')->insert([
            'id' => 99,
            'name' => 'Local stale item',
            'quantity' => 1,
            'company_id' => 123,
        ]);
        DB::connection('cloudways_testing')->table('cloud_items')->insert([
            'id' => 1,
            'name' => 'Remote first item',
            'quantity' => 10,
        ]);
        DB::connection('cloudways_testing')->table('compatible_items')->insert([
            'id' => 1,
            'name' => 'Remote compatible item',
        ]);

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['cloud_items', 'compatible_items'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Synced 1 table(s) and 1 row(s) from Cloudways.')
            ->assertJsonPath('sync.synced_tables', 1)
            ->assertJsonPath('sync.rows', 1)
            ->assertJsonPath('sync.tables.0.name', 'compatible_items')
            ->assertJsonPath('sync.skipped_tables.0', 'cloud_items')
            ->assertJsonPath('sync.skipped_table_details.0.reason', 'missing_required_columns')
            ->assertJsonPath('sync.skipped_table_details.0.missing_required_columns.0', 'company_id');

        $this->assertDatabaseHas('cloud_items', [
            'id' => 99,
            'name' => 'Local stale item',
            'quantity' => 1,
            'company_id' => 123,
        ]);
        $this->assertDatabaseHas('compatible_items', [
            'id' => 1,
            'name' => 'Remote compatible item',
        ]);
    }

    public function test_cloudways_sync_skips_same_named_tables_with_no_matching_columns_without_deleting_local_rows(): void
    {
        $this->configureCloudwaysTestingConnection();

        Schema::create('schema_mismatch_items', function (Blueprint $table): void {
            $table->string('local_note')->nullable();
        });
        Schema::connection('cloudways_testing')->create('schema_mismatch_items', function (Blueprint $table): void {
            $table->string('remote_note')->nullable();
        });

        DB::table('schema_mismatch_items')->insert([
            'local_note' => 'Keep this local row',
        ]);
        DB::connection('cloudways_testing')->table('schema_mismatch_items')->insert([
            'remote_note' => 'Remote row',
        ]);

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['schema_mismatch_items'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Synced 0 table(s) and 0 row(s) from Cloudways.')
            ->assertJsonPath('sync.synced_tables', 0)
            ->assertJsonPath('sync.skipped_tables.0', 'schema_mismatch_items')
            ->assertJsonPath('sync.skipped_table_details.0.reason', 'no_matching_columns')
            ->assertJsonPath('sync.skipped_table_details.0.message', 'Skipped schema_mismatch_items: no matching columns.');

        $this->assertDatabaseHas('schema_mismatch_items', [
            'local_note' => 'Keep this local row',
        ]);
    }

    public function test_cloudways_sync_repairs_latest_realtime_price_links(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudStockTables('cloudways_testing');

        $now = '2026-06-14 10:00:00';

        DB::connection('cloudways_testing')->table('stock_holdings')->insert([
            'id' => 123,
            'symbol' => 'ARGT',
            'latest_realtime_price_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('cloudways_testing')->table('stock_realtime_prices')->insert([
            $this->cloudRealtimePriceAttributes(
                id: 990,
                stockHoldingId: 123,
                quoteHash: str_repeat('a', 64),
                price: '96.12000000',
                asOf: '2026-06-13 20:00:00',
                now: $now,
            ),
            $this->cloudRealtimePriceAttributes(
                id: 991,
                stockHoldingId: 123,
                quoteHash: str_repeat('b', 64),
                price: '97.92000000',
                asOf: '2026-06-14 20:00:00',
                now: $now,
            ),
        ]);

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['stock_holdings', 'stock_realtime_prices'],
            ])
            ->assertOk()
            ->assertJsonPath('sync.synced_tables', 2);

        $this->assertDatabaseHas('stock_holdings', [
            'id' => 123,
            'symbol' => 'ARGT',
            'latest_realtime_price_id' => 991,
        ]);
    }

    public function test_cloudways_sync_repairs_latest_realtime_price_link_using_newest_selectable_price(): void
    {
        $this->configureCloudwaysTestingConnection();
        $this->createCloudStockTables('cloudways_testing');

        $now = '2026-06-14 10:00:00';

        DB::connection('cloudways_testing')->table('stock_holdings')->insert([
            'id' => 123,
            'symbol' => 'ARGT',
            'latest_realtime_price_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('cloudways_testing')->table('stock_realtime_prices')->insert([
            $this->cloudRealtimePriceAttributes(
                id: 990,
                stockHoldingId: 123,
                quoteHash: str_repeat('a', 64),
                price: '96.12000000',
                asOf: '2026-06-13 20:00:00',
                now: $now,
            ),
            $this->cloudRealtimePriceAttributes(
                id: 991,
                stockHoldingId: 123,
                quoteHash: str_repeat('b', 64),
                price: '97.92000000',
                asOf: '2026-06-14 20:00:00',
                now: $now,
                freshnessStatus: 'stale',
            ),
        ]);

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync', [
                'tables' => ['stock_holdings', 'stock_realtime_prices'],
            ])
            ->assertOk()
            ->assertJsonPath('sync.synced_tables', 2);

        $this->assertDatabaseHas('stock_holdings', [
            'id' => 123,
            'latest_realtime_price_id' => 990,
        ]);
    }

    public function test_cloudways_sync_does_not_link_invalid_realtime_price_and_keeps_tables_identical(): void
    {
        $this->configureCloudwaysTestingConnection();
        Config::set('database.connections.cloudways_target_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('cloudways_target_testing');

        $this->createCloudStockTables('cloudways_testing');
        $this->createCloudStockTables('cloudways_target_testing');

        $now = '2026-06-14 10:00:00';

        DB::connection('cloudways_testing')->table('stock_holdings')->insert([
            'id' => 123,
            'symbol' => 'ARGT',
            'latest_realtime_price_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('cloudways_testing')->table('stock_realtime_prices')->insert(
            $this->cloudRealtimePriceAttributes(
                id: 990,
                stockHoldingId: 123,
                quoteHash: str_repeat('a', 64),
                price: '96.12000000',
                asOf: '2026-06-14 20:00:00',
                now: $now,
                validationStatus: 'invalid',
            ),
        );

        $cloudwaysDatabaseSync = app(CloudwaysDatabaseSync::class);
        $sync = $cloudwaysDatabaseSync->syncAllTables(
            checkedDifferentTables: ['stock_holdings', 'stock_realtime_prices'],
            sourceConnectionName: 'cloudways_testing',
            targetConnectionName: 'cloudways_target_testing',
        );

        $this->assertSame(2, $sync['synced_tables']);
        $this->assertNull(
            DB::connection('cloudways_target_testing')
                ->table('stock_holdings')
                ->where('id', 123)
                ->value('latest_realtime_price_id'),
        );

        $comparison = $cloudwaysDatabaseSync->compareAllTables(
            sourceConnectionName: 'cloudways_testing',
            targetConnectionName: 'cloudways_target_testing',
        );
        $stockHoldings = collect($comparison['tables'])->firstWhere('name', 'stock_holdings');

        $this->assertSame('identical', $stockHoldings['status']);
        $this->assertSame('Contents match.', $stockHoldings['message']);
    }

    public function test_regular_admin_cannot_sync_cloudways_tables(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/admin/cloudways/sync')
            ->assertForbidden();
    }

    public function test_guest_cannot_sync_cloudways_tables(): void
    {
        $this->postJson('/admin/cloudways/sync')->assertUnauthorized();
    }

    public function test_cloudways_dynamic_connection_uses_a_short_connect_timeout(): void
    {
        Config::set('services.cloudways.connection', null);
        Config::set('services.cloudways.host', 'cloudways.example.test');
        Config::set('services.cloudways.port', 3306);
        Config::set('services.cloudways.database', 'stocks');
        Config::set('services.cloudways.username', 'stocks');
        Config::set('services.cloudways.password', 'secret');
        Config::set('services.cloudways.connect_timeout', 3);

        $sourceConnectionName = new ReflectionMethod(CloudwaysDatabaseSync::class, 'sourceConnectionName');
        $sourceConnectionName->setAccessible(true);

        $this->assertSame('cloudways', $sourceConnectionName->invoke(app(CloudwaysDatabaseSync::class)));
        $this->assertSame(3, config('database.connections.cloudways.options')[PDO::ATTR_TIMEOUT]);

        DB::purge('cloudways');
    }

    private function configureCloudwaysTestingConnection(): void
    {
        $this->cloudwaysDatabasePath = storage_path('framework/testing-cloudways-'.Str::uuid().'.sqlite');
        touch($this->cloudwaysDatabasePath);

        Config::set('database.connections.cloudways_testing', [
            'driver' => 'sqlite',
            'database' => $this->cloudwaysDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('services.cloudways.connection', 'cloudways_testing');
        Config::set('services.cloudways.sync_tables', [
            'cloud_items',
            'compatible_items',
            'json_items',
            'local_only_items',
            'remote_only_items',
            'schema_mismatch_items',
            'stock_holdings',
            'stock_realtime_prices',
        ]);

        DB::purge('cloudways_testing');
    }

    private function createCloudItemsTable(?string $connection = null): void
    {
        $createTable = function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('quantity');
        };

        if ($connection === null) {
            Schema::create('cloud_items', $createTable);

            return;
        }

        Schema::connection($connection)->create('cloud_items', $createTable);
    }

    private function createCloudItemsTableWithRequiredLocalColumn(): void
    {
        Schema::create('cloud_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('quantity');
            $table->unsignedBigInteger('company_id');
        });
    }

    private function createCompatibleItemsTable(?string $connection = null): void
    {
        $createTable = function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        };

        if ($connection === null) {
            Schema::create('compatible_items', $createTable);

            return;
        }

        Schema::connection($connection)->create('compatible_items', $createTable);
    }

    private function createJsonItemsTable(?string $connection = null): void
    {
        if ($connection !== null) {
            Schema::connection($connection)->create('json_items', function (Blueprint $table): void {
                $table->id();
                $table->text('payload');
            });

            return;
        }

        $database = DB::connection();

        if ($database->getDriverName() === 'sqlite') {
            $database->statement(
                'create table "json_items" ("id" integer primary key autoincrement not null, "payload" json not null)',
            );

            return;
        }

        $createTable = function (Blueprint $table): void {
            $table->id();
            $table->json('payload');
        };

        Schema::create('json_items', $createTable);
    }

    private function createCloudStockTables(string $connection): void
    {
        Schema::connection($connection)->create('stock_holdings', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol')->nullable();
            $table->unsignedBigInteger('latest_realtime_price_id')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('stock_realtime_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_holding_id')->nullable();
            $table->string('instrument_key');
            $table->string('quote_hash', 64);
            $table->string('source_key');
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_quality');
            $table->string('symbol')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->string('price_type');
            $table->timestamp('as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->string('freshness_status');
            $table->string('validation_status');
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cloudRealtimePriceAttributes(
        int $id,
        int $stockHoldingId,
        string $quoteHash,
        string $price,
        string $asOf,
        string $now,
        string $validationStatus = 'valid',
        string $freshnessStatus = 'closed_market',
    ): array {
        return [
            'id' => $id,
            'stock_holding_id' => $stockHoldingId,
            'instrument_key' => 'isin:US37950E2596',
            'quote_hash' => $quoteHash,
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://eodhd.com/api/real-time/ARGT.US?fmt=json',
            'source_quality' => 'market_data_vendor',
            'symbol' => 'ARGT',
            'currency' => 'USD',
            'price' => $price,
            'price_type' => 'last',
            'as_of' => $asOf,
            'fetched_at' => $now,
            'freshness_status' => $freshnessStatus,
            'validation_status' => $validationStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function superAdminUser(): User
    {
        Role::findOrCreate('super_admin');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }
}
