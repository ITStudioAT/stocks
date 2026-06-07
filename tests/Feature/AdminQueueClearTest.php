<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminQueueClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_clear_queue_jobs_and_failed_job_records(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.retry_after', 2100);
        Config::set('queue.connections.database.queue', 'default');

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 1,
                'reserved_at' => now()->timestamp,
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
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Failed while testing.',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/queue/clear')
            ->assertOk()
            ->assertJsonPath('cleared_jobs', 2)
            ->assertJsonPath('cleared_failed_jobs', 1)
            ->assertJsonPath('queue.status', 'ok')
            ->assertJsonPath('queue.pending', 0)
            ->assertJsonPath('queue.reserved', 0)
            ->assertJsonPath('queue.failed', 0);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_guest_cannot_clear_queue_jobs(): void
    {
        $this->postJson('/admin/queue/clear')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
