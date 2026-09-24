<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\StockAiResearch;
use App\Models\StockHolding;
use App\Models\User;
use App\Services\AdminPasswordAuthenticator;
use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewSnapshotArchive;
use App\Services\PreviewSnapshotDatabase;
use App\Services\PreviewSnapshotStream;
use App\Services\PreviewSnapshotTransfer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_streamed_original_data_preserves_json_objects_and_restores_backup(): void
    {
        [$database, $original, $before] = $this->fixtures();
        $original['app_configs'][] = ['id' => 99, 'key' => 'test.original', 'value' => '{"api_token":"old-token","metadata":{},"rows":[],"amount":12.5}', 'created_at' => null, 'updated_at' => null];
        $original = (new PreviewOriginalPolicy)->prepare($original);
        $beforeSummary = $database->summarize($database->records());
        $records = fn (): iterable => $database->recordsFromTables($original);
        $expected = $database->summarize($records());
        $this->assertSame($expected, $database->importRecords($records, $beforeSummary['sha256'], rehearsal: true));
        $this->assertSame($beforeSummary, $database->summarize($database->records()));
        $this->assertSame($expected, $database->importRecords($records, $beforeSummary['sha256']));
        $json = json_decode(DB::table('app_configs')->where('key', 'test.original')->value('value'));
        $this->assertInstanceOf(\stdClass::class, $json->metadata);
        $this->assertSame([], $json->rows);
        $this->assertSame('[preview-redacted]', $json->api_token);
        $database->importRecords(fn (): iterable => $database->recordsFromTables($before), $expected['sha256']);
        $this->assertSame($beforeSummary, $database->summarize($database->records()));
    }

    public function test_streamed_cross_user_research_reference_rolls_back(): void
    {
        [$database, $original] = $this->fixtures();
        $original['stock_ai_researches'][1]['previous_research_id'] = $original['stock_ai_researches'][0]['id'];
        $before = $database->summarize($database->records());
        try {
            $database->importRecords(fn (): iterable => $database->recordsFromTables($original), $before['sha256']);
            $this->fail('Cross-user history should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership', $exception->getMessage());
            $this->assertSame($before, $database->summarize($database->records()));
        }
    }

    public function test_stream_failure_after_inserts_rolls_back_before_commit(): void
    {
        [$database, $original] = $this->fixtures();
        $before = $database->summarize($database->records());
        $passes = 0;
        $records = function () use ($database, $original, &$passes): iterable {
            $passes++;
            foreach ($database->recordsFromTables($original) as $record) {
                yield $record;
                if ($passes === 2) {
                    throw new RuntimeException('Injected stream authentication failure.');
                }
            }
        };
        try {
            $database->importRecords($records, $before['sha256']);
            $this->fail('Stream failure should roll back.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Injected', $exception->getMessage());
            $this->assertSame($before, $database->summarize($database->records()));
        }
    }

    #[DataProvider('freshTransferActions')]
    public function test_fresh_transfer_instances_can_restore_and_finish_json_snapshots(bool $restore): void
    {
        [$database, $original] = $this->fixtures();
        $original['app_configs'][] = ['id' => 99, 'key' => 'original.json', 'value' => '{"z":{"nested":true},"a":{},"rows":[]}', 'created_at' => null, 'updated_at' => null];
        DB::table('app_configs')->insert(['id' => 1, 'key' => 'preview.json', 'value' => '{"z":1,"a":{},"rows":[]}']);
        $before = $database->summarize($database->records());
        $directory = sys_get_temp_dir().'/stocks-fresh-transfer-'.bin2hex(random_bytes(10));
        mkdir($directory.'/private', 0700, true);
        mkdir($directory.'/framework/sessions', 0700, true);
        mkdir($directory.'/framework/cache/data', 0700, true);
        $directory = realpath($directory);

        try {
            $makeTransfer = fn (): PreviewSnapshotTransfer => new PreviewSnapshotTransfer(
                new PreviewSnapshotDatabase(DB::getPdo(), new PreviewOriginalSchema),
                new PreviewSnapshotArchive(new PreviewOriginalPolicy),
                realpath($directory.'/private'),
                $directory.'/framework/down',
                $directory.'/framework/sessions',
                $directory.'/framework/cache/data',
            );
            $context = ['source_app_id' => '100', 'target_app_id' => '200', 'source_commit' => str_repeat('a', 40), 'target_commit' => str_repeat('b', 40), 'nonce' => str_repeat('c', 64)];
            $request = $makeTransfer()->prepare($context);
            $snapshot = $directory.'/original.snapshot';
            $receipt = (new PreviewSnapshotStream)->seal($database->recordsFromTables($original), $context, hex2bin($request['recipient']), $snapshot);
            $imported = $makeTransfer()->importStream($snapshot, $receipt['sha256']);
            $this->assertFileExists($directory.'/framework/down');

            if ($restore) {
                $makeTransfer()->restore();
                $this->assertSame($before, $database->summarize($database->records()));
                $this->assertSame('preview-admin@stocks.invalid', User::findOrFail(1)->email);
            } else {
                $this->assertSame($imported['stream_sha256'], $database->summarize($database->records())['sha256']);
                $this->assertSame('first@original.test', User::findOrFail(1)->email);
            }

            $makeTransfer()->finish();
            $this->assertFileDoesNotExist($directory.'/framework/down');
            $this->assertFileExists($directory.'/private/released.json');
            $expectedKey = $restore ? 'preview.json' : 'original.json';
            $value = json_decode(DB::table('app_configs')->where('key', $expectedKey)->value('value'));
            $this->assertInstanceOf(\stdClass::class, $value->a);
            $this->assertSame([], $value->rows);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public static function freshTransferActions(): array
    {
        return ['release original data' => [false], 'restore preview backup' => [true]];
    }

    public function test_mysql_large_existing_rows_can_be_locked_for_rehearsal_without_buffering_payloads(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the dedicated isolated MySQL CI service.');
        }

        $database = new PreviewSnapshotDatabase(DB::getPdo(), new PreviewOriginalSchema);
        $database->assertSchema();
        $payload = json_encode(['payload' => str_repeat('x', 1_000_000)], JSON_THROW_ON_ERROR);
        for ($index = 1; $index <= 24; $index++) {
            DB::table('app_configs')->insert(['id' => $index, 'key' => 'large.fixture.'.$index, 'value' => $payload]);
        }
        unset($payload);
        $before = $database->summarize($database->records());
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $database->importRecords(fn (): iterable => [], $before['sha256'], rehearsal: true);
        $additionalPeak = memory_get_peak_usage() - $baseline;

        $this->assertLessThan(16 * 1024 * 1024, $additionalPeak);
        $this->assertTrue(DB::getPdo()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        $this->assertSame(24, DB::table('app_configs')->count());
        $this->assertSame($before, $database->summarize($database->records()));
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
