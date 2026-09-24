<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\StockAiResearch;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\AdminPasswordAuthenticator;
use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewSnapshotDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PreviewSnapshotDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSnapshotTestDatabase();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    protected function configureSnapshotTestDatabase(): void {}

    public function test_original_users_passwords_roles_and_owned_research_survive_without_preview_admin_collision(): void
    {
        [$database, $original, $before] = $this->fixtures();
        $originalDigest = $database->digest($original);
        $beforeDigest = $database->digest($before);
        $database->import($original, $beforeDigest, rehearsal: true);
        $this->assertSame($beforeDigest, $database->digest($database->read()));
        $this->assertSame($originalDigest, $database->import($original, $beforeDigest));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame('first@original.test', User::findOrFail(1)->email);
        $this->assertSame('second@original.test', User::findOrFail(2)->email);
        $this->assertDatabaseMissing('users', ['email' => 'preview-admin@stocks.invalid']);
        $firstLogin = app(AdminPasswordAuthenticator::class)->authenticate('first@original.test', 'first-password');
        $secondLogin = app(AdminPasswordAuthenticator::class)->authenticate('second@original.test', 'second-password');
        $this->assertSame(1, $firstLogin['user']->id);
        $this->assertSame(2, $secondLogin['user']->id);
        $this->assertNull(app(AdminPasswordAuthenticator::class)->authenticate('second@original.test', 'first-password'));
        $this->assertSame(['First private research'], User::findOrFail(1)->stockAiResearches()->pluck('summary')->all());
        $this->assertSame(['Second private research'], User::findOrFail(2)->stockAiResearches()->pluck('summary')->all());
        $this->assertSame('Original Depot', Depot::firstOrFail()->name);
        $this->assertSame('AT123456', Depot::firstOrFail()->account_number);
        $this->assertSame(0, User::whereNotNull('remember_token')->count());
        $database->restore($before, $originalDigest, $database->protectedDigest());
        $this->assertSame($beforeDigest, $database->digest($database->read()));
        $this->assertSame('preview-admin@stocks.invalid', User::findOrFail(1)->email);
    }

    public function test_changed_target_is_not_overwritten(): void
    {
        [$database, $original, $before] = $this->fixtures();
        $beforeDigest = $database->digest($before);
        User::findOrFail(1)->update(['first_name' => 'Changed']);
        try {
            $database->import($original, $beforeDigest);
            $this->fail('Changed target should be refused.');
        } catch (RuntimeException) {
            $this->assertSame('Changed', User::findOrFail(1)->first_name);
            $this->assertSame(0, Depot::count());
        }
    }

    public function test_constraint_failure_rolls_back_deleted_original_preview_admin(): void
    {
        [$database, $original, $before] = $this->fixtures();
        $beforeDigest = $database->digest($before);
        $original['users'][1]['email'] = $original['users'][0]['email'];
        try {
            $database->import($original, $beforeDigest);
            $this->fail('Duplicate unique email should fail.');
        } catch (\PDOException) {
            $this->assertSame($beforeDigest, $database->digest($database->read()));
        }
    }

    public function test_disabled_foreign_keys_are_rejected(): void
    {
        [$database, $original, $before] = $this->fixtures();
        $mysql = DB::getDriverName() === 'mysql';
        DB::getPdo()->exec($mysql ? 'SET SESSION foreign_key_checks = 0' : 'PRAGMA foreign_keys = OFF');
        try {
            $this->expectException(RuntimeException::class);
            $database->import($original, $database->digest($before));
        } finally {
            DB::getPdo()->exec($mysql ? 'SET SESSION foreign_key_checks = 1' : 'PRAGMA foreign_keys = ON');
        }
    }

    /** @return array{PreviewSnapshotDatabase, array, array} */
    private function fixtures(): array
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $role = Role::findOrCreate('admin', 'web');
        $first = User::factory()->create(['id' => 1, 'email' => 'first@original.test', 'password' => 'first-password']);
        $second = User::factory()->create(['id' => 2, 'email' => 'second@original.test', 'password' => 'second-password']);
        $first->assignRole($role);
        $second->assignRole($role);
        $holding = StockHolding::factory()->create();
        StockAiResearch::factory()->create(['user_id' => $first->id, 'stock_holding_id' => $holding->id, 'summary' => 'First private research']);
        StockAiResearch::factory()->create(['user_id' => $second->id, 'stock_holding_id' => $holding->id, 'summary' => 'Second private research']);
        Depot::factory()->create(['name' => 'Original Depot', 'account_number' => 'AT123456', 'account_balance' => '1234.56', 'is_active' => true]);
        $database = new PreviewSnapshotDatabase(DB::getPdo(), new PreviewOriginalSchema);
        $raw = $database->export();
        $original = (new PreviewOriginalPolicy)->prepare($raw);
        $empty = array_fill_keys(array_keys($raw), []);
        $database->import($empty, $database->digest($raw));
        User::factory()->create(['id' => 1, 'email' => 'preview-admin@stocks.invalid', 'remember_token' => null]);
        $before = $database->export();

        return [$database, $original, $before];
    }
}
