<?php

namespace Tests\Unit;

use App\Services\PreviewReleaseBundle;
use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewDatabaseConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/scripts/preview-configure-database.php';
    }

    #[DataProvider('passwords')]
    public function test_only_the_three_database_fields_change_and_special_characters_round_trip(string $password): void
    {
        $original = "# keep comment\r\nAPP_NAME=Template\r\nAPP_KEY=test-key\r\nDB_HOST=127.0.0.1\r\nDB_PORT=3306\r\nDB_DATABASE=laravel\r\nexport DB_USERNAME=root\r\nDB_PASSWORD=\r\n# unchanged trailing line\r\n";
        $updated = stocksPreviewDatabaseEnvironment($original, $password);
        $values = Dotenv::parse($updated);
        $this->assertSame('hbnucgvzmy', $values['DB_DATABASE']);
        $this->assertSame('hbnucgvzmy', $values['DB_USERNAME']);
        $this->assertSame($password, $values['DB_PASSWORD']);
        $strip = fn (string $contents): string => preg_replace('/^(?:export )?DB_(?:DATABASE|USERNAME|PASSWORD)=[^\r\n]*\r\n/m', '', $contents);
        $this->assertSame($strip($original), $strip($updated));
    }

    public static function passwords(): array
    {
        return [['safe-password'], ['  spaces # hash "quotes" \'single\' \\ ${APP_NAME} $dollar & ; `backtick`  '], ['Grüße!€'], ['trailing\\'], ['#null(false)']];
    }

    #[DataProvider('invalidTemplates')]
    public function test_ambiguous_or_previously_configured_templates_are_rejected(string $contents): void
    {
        $this->expectException(\Throwable::class);
        stocksPreviewDatabaseEnvironment($contents, 'new-safe-password');
    }

    public static function invalidTemplates(): array
    {
        return [
            ["DB_DATABASE=laravel\nDB_USERNAME=root\n"],
            ["DB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=\nDB_PASSWORD=\n"],
            ["DB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=already-configured\n"],
            ["DB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=\nDB_URL=mysql://override\n"],
            ["DB_DATABASE=\"multi\nline\"\nDB_USERNAME=root\nDB_PASSWORD=\n"],
            ['DB_DATABASE=laravel'."\nDB_USERNAME=root\nDB_PASSWORD=\n".'DEPENDENT="${DB_DATABASE}"'."\n"],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_empty_oversized_or_control_character_passwords_are_rejected(string $password): void
    {
        $this->expectException(RuntimeException::class);
        stocksPreviewDatabaseEnvironment("DB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=\n", $password);
    }

    public static function invalidPasswords(): array
    {
        return [[''], [str_repeat('a', 4097)], ["line\nbreak"], ["zero\0byte"]];
    }

    public function test_atomic_update_keeps_a_private_original_and_refuses_stale_contents(): void
    {
        $directory = sys_get_temp_dir().'/stocks-preview-env-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/public_html/.stocks-preview-private', 0700, true);
        $root = realpath($directory.'/public_html');
        $private = realpath($root.'/.stocks-preview-private');
        $original = "APP_KEY=unchanged\nDB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=\n";
        $updated = stocksPreviewDatabaseEnvironment($original, 'new-fixture-password');
        file_put_contents($root.'/.env', $original);
        try {
            stocksPreviewSaveDatabaseEnvironment($root, $private, $original, $updated);
            $this->assertSame($updated, file_get_contents($root.'/.env'));
            $backups = glob($private.'/template-environment-*');
            $this->assertCount(1, $backups);
            $this->assertSame($original, file_get_contents($backups[0]));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($root.'/.env') & 0777);
                $this->assertSame(0600, fileperms($backups[0]) & 0777);
            }
            try {
                stocksPreviewSaveDatabaseEnvironment($root, $private, $original, $updated);
                $this->fail('Stale original contents were accepted.');
            } catch (RuntimeException) {
                $this->assertSame($updated, file_get_contents($root.'/.env'));
                $this->assertCount(1, glob($private.'/template-environment-*'));
            }
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_changed_staging_code_is_rejected_before_autoload_execution(): void
    {
        $directory = sys_get_temp_dir().'/stocks-preview-staging-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/private', 0700, true);
        $private = realpath($directory.'/private');
        $staging = $private.DIRECTORY_SEPARATOR.'release-'.str_repeat('a', 32);
        $source = $directory.'/source';
        mkdir($source.'/public', 0700, true);
        mkdir($source.'/vendor', 0700);
        foreach (['artisan', 'public/index.php', 'vendor/autoload.php'] as $path) {
            file_put_contents($source.'/'.$path, '<?php /* fixture, never execute */');
        }
        $bundles = new PreviewReleaseBundle;
        try {
            $digest = $bundles->create($source, $directory.'/fixture.zip', str_repeat('c', 40));
            $manifest = $bundles->extract($directory.'/fixture.zip', $digest, $staging);
            stocksPreviewVerifyStaging($staging, $private, $manifest);
            file_put_contents($staging.'/vendor/autoload.php', '<?php throw new Exception("must never run");');
            $this->expectException(RuntimeException::class);
            stocksPreviewVerifyStaging($staging, $private, $manifest);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}
