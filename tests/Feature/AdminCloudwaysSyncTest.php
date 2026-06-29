<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CloudwaysDatabaseSync;
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

        if ($this->cloudwaysDatabasePath && file_exists($this->cloudwaysDatabasePath)) {
            unlink($this->cloudwaysDatabasePath);
        }

        parent::tearDown();
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

        $this->actingAs($this->superAdminUser())
            ->postJson('/admin/cloudways/sync')
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
            ->post('/admin/cloudways/sync', [], [
                'Accept' => 'application/x-ndjson',
            ])
            ->assertOk();

        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));

        $events = collect(explode("\n", trim($response->streamedContent())))
            ->map(fn (string $line): array => json_decode($line, true));

        $this->assertSame('table', $events->first()['type']);
        $this->assertSame('cloud_items', $events->first()['table']['name']);
        $this->assertSame('Imported cloud_items: 1 row(s), 3 column(s).', $events->first()['table']['message']);
        $this->assertSame('finished', $events->last()['type']);
        $this->assertSame('Synced 1 table(s) and 1 row(s) from Cloudways.', $events->last()['message']);
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
            ->postJson('/admin/cloudways/sync')
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
            ->postJson('/admin/cloudways/sync')
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
            ->postJson('/admin/cloudways/sync')
            ->assertOk()
            ->assertJsonPath('sync.synced_tables', 2);

        $this->assertDatabaseHas('stock_holdings', [
            'id' => 123,
            'symbol' => 'ARGT',
            'latest_realtime_price_id' => 991,
        ]);
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
            'freshness_status' => 'closed_market',
            'validation_status' => 'valid',
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
