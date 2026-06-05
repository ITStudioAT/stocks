<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminQueueStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_queue_status(): void
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.retry_after', 1200);

        $this->actingAs($this->adminUser())
            ->getJson('/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('queue.status', 'ok')
            ->assertJsonPath('queue.connection', 'sync')
            ->assertJsonPath('queue.name', 'default')
            ->assertJsonPath('queue.retry_after', 1200)
            ->assertJsonPath('queue.max_job_timeout', 900)
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
            ->assertJsonPath('queue.issues.0', 'retry_after (60s) must be greater than max job timeout (900s)');
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
