<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
            ->assertJsonPath('sync.skipped_tables.0', 'remote_only_items');

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
