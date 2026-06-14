<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminQueueStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_queue_status(): void
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.retry_after', 2100);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'ok')
            ->assertJsonPath('queue.connection', 'sync')
            ->assertJsonPath('queue.name', 'default')
            ->assertJsonPath('queue.retry_after', 2100)
            ->assertJsonPath('queue.max_job_timeout', 1800)
            ->assertJsonPath('queue.pending', 0)
            ->assertJsonPath('queue.delayed', 0)
            ->assertJsonPath('queue.reserved', 0)
            ->assertJsonPath('queue.failed', 0)
            ->assertJsonPath('queue.stale_running_refreshes', 0)
            ->assertJsonPath('queue.issues', []);
    }

    public function test_queue_status_warns_when_retry_after_is_too_short(): void
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.retry_after', 60);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'check')
            ->assertJsonPath('queue.retry_after', 60)
            ->assertJsonPath('queue.issues.0', 'retry_after (60s) must be greater than max job timeout (1800s)');
    }

    public function test_queue_status_warns_when_queue_size_cannot_be_checked(): void
    {
        Config::set('queue.default', 'missing');
        Config::set('queue.connections.missing.queue', 'default');
        Config::set('queue.connections.missing.retry_after', 2100);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'check')
            ->assertJsonPath('queue.connection', 'missing')
            ->assertJsonPath('queue.pending', null)
            ->assertJsonPath('queue.delayed', null)
            ->assertJsonPath('queue.reserved', null)
            ->assertJsonPath('queue.issues.0', 'Queue size could not be checked for missing:default');
    }

    public function test_queue_status_shows_waiting_when_jobs_are_pending_without_a_reserved_worker(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.retry_after', 2100);
        Config::set('queue.connections.database.queue', 'default');

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'waiting')
            ->assertJsonPath('queue.pending', 2)
            ->assertJsonPath('queue.reserved', 0)
            ->assertJsonPath('queue.issues', []);
    }

    public function test_queue_status_does_not_warn_for_a_currently_reserved_job(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.retry_after', 2100);
        Config::set('queue.connections.database.queue', 'default');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'ok')
            ->assertJsonPath('queue.pending', 0)
            ->assertJsonPath('queue.reserved', 1)
            ->assertJsonPath('queue.issues', []);
    }

    public function test_guest_cannot_view_queue_status(): void
    {
        $this->getJson('/admin/queue/status')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
