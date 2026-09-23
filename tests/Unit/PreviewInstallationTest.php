<?php

namespace Tests\Unit;

use App\Services\PreviewDatabaseGuard;
use App\Services\PreviewInstallation;
use App\Services\PreviewReleaseBundle;
use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewInstallationTest extends TestCase
{
    private string $directory;

    private string $root;

    private array $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stocks-preview-install-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/preview123/public_html/public', 0700, true);
        $this->root = realpath($this->directory.'/preview123/public_html');
        file_put_contents($this->root.'/public/template.txt', 'preserve old template');
        file_put_contents($this->root.'/.env', 'LIVE_API_KEY=do-not-copy');
        $this->target = ['serverId' => '1', 'targetAppId' => '2', 'sourceAppId' => '3', 'targetFolder' => 'preview123', 'targetDatabase' => 'preview123', 'sourceDatabase' => 'live123', 'targetDatabaseUser' => 'previewuser', 'sourceDatabaseUser' => 'liveuser', 'targetUrl' => 'https://preview.example.test', 'sourceUrl' => 'https://live.example.test'];
    }

    public function test_activation_preserves_entire_template_and_creates_only_fresh_preview_configuration(): void
    {
        [$archive, $digest] = $this->bundle();
        $installer = $this->installer();
        $result = $installer->activate($archive, $digest, $this->root, fileowner($this->root), fn (): array => [$this->connection([]), $this->database()]);
        $this->assertSame('pending', $result['state']);
        $this->assertSame('preserve old template', file_get_contents($result['backup'].'/public/template.txt'));
        $this->assertFileDoesNotExist($this->root.'/public/template.txt');
        $environment = Dotenv::parse(file_get_contents($this->root.'/.env'));
        $this->assertSame('preview', $environment['APP_ENV']);
        $this->assertSame('preview123', $environment['DB_DATABASE']);
        $this->assertSame($this->database()['password'], $environment['DB_PASSWORD']);
        $this->assertArrayNotHasKey('LIVE_API_KEY', $environment);
        $this->assertFileExists($this->root.'/storage/framework/down');
        $marker = json_decode(file_get_contents($this->root.'/storage/framework/stocks-preview-instance'), true);
        $this->assertSame(hash('sha256', base64_decode(substr($environment['APP_KEY'], 7))), $marker['key_sha256']);
        $access = json_decode(file_get_contents($this->root.'/storage/app/private/preview-access.json'), true);
        $this->assertTrue(password_verify($access['access_password'], $environment['PREVIEW_ACCESS_PASSWORD_HASH']));
    }

    public function test_existing_database_tables_stop_before_any_template_replacement(): void
    {
        [$archive, $digest] = $this->bundle();
        try {
            $this->installer()->activate($archive, $digest, $this->root, fileowner($this->root), fn (): array => [$this->connection([['users', 'BASE TABLE']]), $this->database()]);
            $this->fail('Existing preview tables were accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empty preview database', $exception->getMessage());
            $this->assertSame('preserve old template', file_get_contents($this->root.'/public/template.txt'));
            $this->assertFileDoesNotExist($this->root.'/storage/framework/stocks-preview-instance');
        }
    }

    public function test_template_can_be_restored_without_deleting_the_new_installation_or_database(): void
    {
        [$archive, $digest] = $this->bundle();
        $installer = $this->installer();
        $owner = fileowner($this->root);
        $installer->activate($archive, $digest, $this->root, $owner, fn (): array => [$this->connection([]), $this->database()]);
        $preserved = $installer->restoreTemplate($this->root, $owner, str_repeat('a', 40));
        $this->assertSame('preserve old template', file_get_contents($this->root.'/public/template.txt'));
        $this->assertSame('LIVE_API_KEY=do-not-copy', file_get_contents($this->root.'/.env'));
        $this->assertFileExists($preserved.'/storage/framework/stocks-preview-instance');
        $this->assertFileExists($preserved.'/.env');
    }

    public function test_live_credentials_are_rejected_before_connection(): void
    {
        $database = $this->database();
        $database['database'] = 'live123';
        $this->expectException(RuntimeException::class);
        $this->installer()->validateDatabaseConfiguration($database);
    }

    public function test_restore_respects_lock_and_refuses_an_active_installation(): void
    {
        [$archive, $digest] = $this->bundle();
        $installer = $this->installer();
        $owner = fileowner($this->root);
        $installer->activate($archive, $digest, $this->root, $owner, fn (): array => [$this->connection([]), $this->database()]);
        $lock = fopen(dirname($this->root).'/.stocks-preview-private/installation.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $installer->restoreTemplate($this->root, $owner, str_repeat('a', 40));
            $this->fail('Concurrent restore was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('running', $exception->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $markerPath = $this->root.'/storage/framework/stocks-preview-instance';
        $marker = json_decode(file_get_contents($markerPath), true);
        $marker['state'] = 'active';
        file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $installer->restoreTemplate($this->root, $owner, str_repeat('a', 40));
    }

    public function test_wrong_owner_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->installer()->assertTarget($this->root, fileowner($this->root) + 1);
    }

    private function installer(): PreviewInstallation
    {
        $guard = $this->createMock(PreviewDatabaseGuard::class);
        $guard->method('assertScopedGrants');

        return new PreviewInstallation($this->target, new PreviewReleaseBundle, $guard);
    }

    private function connection(array $tables): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchAll')->willReturn($tables);
        $connection = $this->createMock(PDO::class);
        $connection->method('query')->with('SHOW FULL TABLES')->willReturn($statement);
        $connection->expects($this->never())->method('exec');

        return $connection;
    }

    private function database(): array
    {
        return ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'preview123', 'username' => 'previewuser', 'password' => 'quoted-"-password\\end#value${APP_NAME}'];
    }

    private function bundle(): array
    {
        $source = $this->directory.'/source';
        foreach (['artisan', 'vendor/autoload.php', 'public/index.php', 'public/build/manifest.json', 'bootstrap/app.php', 'app/Services/PreviewIsolation.php'] as $path) {
            if (! is_dir(dirname($source.'/'.$path))) {
                mkdir(dirname($source.'/'.$path), 0700, true);
            }
            file_put_contents($source.'/'.$path, 'preview fixture');
        }
        $archive = $this->directory.'/release.zip';
        $digest = (new PreviewReleaseBundle)->create($source, $archive, str_repeat('a', 40));

        return [$archive, $digest];
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }
}
