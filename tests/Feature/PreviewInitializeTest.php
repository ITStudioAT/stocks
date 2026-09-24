<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PreviewIsolation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PreviewInitializeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $databasePath = database_path();
        $this->directory = sys_get_temp_dir().'/stocks-preview-initialize-test-'.bin2hex(random_bytes(8));
        $root = $this->directory.'/preview/public_html';
        foreach (['storage/framework', 'storage/app/private', 'public', 'vendor'] as $path) {
            mkdir($root.'/'.$path, 0700, true);
        }
        mkdir($root.'/.stocks-preview-private', 0700);
        $this->app->setBasePath($root);
        $this->app->useStoragePath($root.'/storage');
        $this->app->useDatabasePath($databasePath);
        config([
            'security.preview.source_app_id' => '100', 'security.preview.target_app_id' => '200',
            'security.preview.access_password_hash' => password_hash('preview-access', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $isolation = Mockery::mock(PreviewIsolation::class)->makePartial();
        $isolation->shouldReceive('active')->andReturn(true);
        $isolation->shouldReceive('problems')->andReturn([]);
        $this->app->instance(PreviewIsolation::class, $isolation);
        file_put_contents(storage_path('framework/stocks-preview-instance'), json_encode([
            'format' => 'stocks-preview-instance-v1', 'source_app_id' => '100', 'target_app_id' => '200',
            'root' => realpath(base_path()), 'commit' => str_repeat('a', 40), 'state' => 'pending', 'prior_database_empty' => true,
        ], JSON_THROW_ON_ERROR));
        file_put_contents(storage_path('framework/down'), json_encode(['stocks_preview_commit' => str_repeat('a', 40)], JSON_THROW_ON_ERROR));
        file_put_contents(storage_path('app/private/preview-access.json'), json_encode(['access_username' => 'preview', 'access_password' => 'preview-access'], JSON_THROW_ON_ERROR));
        $files = [];
        foreach (['artisan', 'public/index.php', 'vendor/autoload.php'] as $path) {
            file_put_contents(base_path($path), 'fixture');
            $files[$path] = hash('sha256', 'fixture');
        }
        file_put_contents(base_path('preview-release.json'), json_encode(['format' => 'stocks-preview-release-v1', 'commit' => str_repeat('a', 40), 'files' => $files], JSON_THROW_ON_ERROR));
        $marker = $isolation->installationMarker();
        $marker['manifest_sha256'] = hash_file('sha256', base_path('preview-release.json'));
        file_put_contents(storage_path('framework/stocks-preview-instance'), json_encode($marker, JSON_THROW_ON_ERROR));
    }

    public function test_empty_test_database_initializes_with_its_own_admin_and_stays_in_maintenance(): void
    {
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertSuccessful();
        $user = User::query()->sole();
        $credentials = json_decode(file_get_contents(storage_path('app/private/preview-access.json')), true);
        $this->assertSame('preview-admin@stocks.invalid', $user->email);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue(Hash::check($credentials['admin_password'], $user->password));
        $this->assertNotNull($user->password_initialized_at);
        $this->assertFileExists(storage_path('framework/down'));
        $this->assertSame('initialized', app(PreviewIsolation::class)->installationMarker()['state']);
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
        $this->assertSame(1, User::query()->count());
    }

    public function test_existing_table_is_preserved_without_migrating(): void
    {
        Schema::create('preserve_me', fn ($table) => $table->id());
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
        $this->assertTrue(Schema::hasTable('preserve_me'));
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_wrong_commit_stops_before_schema_changes(): void
    {
        $this->artisan('preview:initialize', ['--commit' => str_repeat('b', 40)])->assertFailed();
        $this->assertSame([], Schema::getTables());
    }

    public function test_changed_source_stops_before_schema_changes(): void
    {
        file_put_contents(base_path('artisan'), 'changed source');
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
        $this->assertSame([], Schema::getTables());
    }

    public function test_activation_requires_verified_web_php_and_does_not_remove_manual_maintenance(): void
    {
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertSuccessful();
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.3.25'])->assertFailed();
        $this->assertFileExists(storage_path('framework/down'));
        file_put_contents(storage_path('framework/down'), '{}');
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.4.25'])->assertFailed();
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function test_initialized_private_preview_can_be_activated_for_verified_source_and_php(): void
    {
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertSuccessful();
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.4.25'])->assertSuccessful();
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertSame('active', app(PreviewIsolation::class)->installationMarker()['state']);
    }

    public function test_activation_rechecks_source_after_initialization(): void
    {
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertSuccessful();
        file_put_contents(base_path('public/index.php'), 'changed after initialization');
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.4.25'])->assertFailed();
        $this->assertFileExists(storage_path('framework/down'));
        $this->assertSame('initialized', app(PreviewIsolation::class)->installationMarker()['state']);
    }

    public function test_initialization_respects_the_shared_installation_lock(): void
    {
        $lock = app(PreviewIsolation::class)->lockInstallation();
        try {
            $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
            $this->assertSame([], Schema::getTables());
            $this->assertSame('pending', app(PreviewIsolation::class)->installationMarker()['state']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_pending_file_exchange_prevents_initialization_and_activation(): void
    {
        file_put_contents(base_path('.stocks-preview-private/swap.json'), '{}');
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.4.25'])->assertFailed();
        $this->assertSame([], Schema::getTables());
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function test_private_backups_are_excluded_but_similarly_named_source_paths_are_not(): void
    {
        file_put_contents(base_path('.stocks-preview-private/backup.env'), 'private backup');
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertSuccessful();
        mkdir(base_path('.stocks-preview-private-extra'));
        file_put_contents(base_path('.stocks-preview-private-extra/unlisted.php'), 'unlisted code');
        $this->artisan('preview:activate', ['--commit' => str_repeat('a', 40), '--web-php' => '8.4.25'])->assertFailed();
        $this->assertFileExists(storage_path('framework/down'));
    }

    public function test_private_directory_symlink_is_not_accepted_as_an_exclusion(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows symlink creation requires separate privileges; Linux CI covers this boundary.');
        }
        rmdir(base_path('.stocks-preview-private'));
        mkdir($this->directory.'/outside', 0700);
        symlink($this->directory.'/outside', base_path('.stocks-preview-private'));
        $this->artisan('preview:initialize', ['--commit' => str_repeat('a', 40)])->assertFailed();
        $this->assertSame([], Schema::getTables());
        $this->assertFileExists(storage_path('framework/down'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }
}
