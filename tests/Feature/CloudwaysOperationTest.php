<?php

namespace Tests\Feature;

use App\Jobs\RunCloudwaysOperation;
use App\Models\User;
use App\Services\CloudwaysDatabaseSync;
use App\Services\CloudwaysOperation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CloudwaysOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.file.driver' => 'array']);
        Cache::purge('file');
        Queue::fake();
    }

    public function test_check_and_sync_run_in_queue_and_progress_is_available_after_navigation(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin'));
        $this->actingAs($user);
        $this->postJson('/admin/cloudways/operation', ['action' => 'sync'])->assertUnprocessable();
        $run = $this->postJson('/admin/cloudways/operation', ['action' => 'check'])->assertOk()->json('run');
        $this->assertSame('queued', $run['status']);
        Queue::assertPushed(RunCloudwaysOperation::class, 1);
        $this->postJson('/admin/cloudways/operation', ['action' => 'check'])->assertJsonPath('run.id', $run['id']);
        Queue::assertPushed(RunCloudwaysOperation::class, 1);
        $comparison = ['tables' => [['name' => 'depots', 'status' => 'different'], ['name' => 'stocks', 'status' => 'identical']]];
        $sync = Mockery::mock(CloudwaysDatabaseSync::class);
        $sync->shouldReceive('compareAllTables')->once()->andReturnUsing(function ($source = null, $target = null, $onTableCompared = null, $onProgress = null) use ($comparison): array {
            $onTableCompared($comparison['tables'][0], 1, 2);
            $this->getJson('/admin/cloudways/operation')
                ->assertJsonPath('run.status', 'running')
                ->assertJsonPath('run.checked_tables.0.name', 'depots')
                ->assertJsonPath('run.checked_tables.0.status', 'different');
            $onTableCompared($comparison['tables'][1], 2, 2);

            return $comparison;
        });
        (new RunCloudwaysOperation($run['id'], 'check', []))->handle($sync, app(CloudwaysOperation::class));
        $this->getJson('/admin/cloudways/operation')->assertJsonPath('run.status', 'finished')->assertJsonPath('run.comparison.tables.0.name', 'depots')->assertJsonPath('run.checked_tables.1.name', 'stocks');
        $next = $this->postJson('/admin/cloudways/operation', ['action' => 'sync'])->assertOk()->json('run');
        Queue::assertPushed(RunCloudwaysOperation::class, fn ($job): bool => $job->action === 'sync' && $job->tables === ['depots']);
        $sync->shouldReceive('syncAllTables')->once()->andReturn(['synced_tables' => 1, 'rows' => 3]);
        (new RunCloudwaysOperation($next['id'], 'sync', ['depots']))->handle($sync, app(CloudwaysOperation::class));
        $this->getJson('/admin/cloudways/operation')->assertJsonPath('run.status', 'finished')->assertJsonPath('run.sync.rows', 3);
    }

    public function test_cloudways_operation_is_restricted_to_super_admins(): void
    {
        $this->getJson('/admin/cloudways/operation')->assertUnauthorized();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user)->postJson('/admin/cloudways/operation', ['action' => 'check'])->assertForbidden();
    }

    public function test_cloudways_is_advertised_only_locally_and_online_endpoints_are_blocked(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin'));
        $this->actingAs($user);
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->app['env'] = 'local';
        $this->getJson('/admin/me')->assertOk()->assertJsonPath('user.cloudways_available', true);
        $this->app['env'] = 'production';
        $this->getJson('/admin/me')->assertOk()->assertJsonPath('user.cloudways_available', false);
        $this->getJson('/admin/cloudways/operation')->assertForbidden();
        $this->getJson('/admin/cloudways/status')->assertForbidden();
        $this->postJson('/admin/cloudways/check')->assertForbidden();
        $this->postJson('/admin/cloudways/operation', ['action' => 'check'])->assertForbidden();
        $this->app['env'] = 'testing';
    }
}
