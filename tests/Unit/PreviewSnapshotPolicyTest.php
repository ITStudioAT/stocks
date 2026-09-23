<?php

namespace Tests\Unit;

use App\Services\PreviewDatabaseGuard;
use App\Services\PreviewSnapshotPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PreviewSnapshotPolicyTest extends TestCase
{
    public function test_business_identifiers_and_balances_survive_without_private_notes_or_api_payloads(): void
    {
        $input = [
            'depots' => [['id' => 7, 'name' => 'Private name', 'account_number' => 'private-account', 'account_balance' => '123.45']],
            'depot_transactions' => [['id' => 9, 'depot_id' => 7, 'pieces' => '2.50', 'note' => 'private note']],
            'stock_prices' => [['id' => 3, 'price' => '45.67', 'raw_payload' => '{"api_token":"secret"}', 'source_url' => 'https://source.test/?token=secret']],
        ];
        $result = (new PreviewSnapshotPolicy)->sanitize($input);
        $this->assertSame('123.45', $result['depots'][0]['account_balance']);
        $this->assertSame('Preview Depot 7', $result['depots'][0]['name']);
        $this->assertSame(7, $result['depot_transactions'][0]['depot_id']);
        $this->assertSame('2.50', $result['depot_transactions'][0]['pieces']);
        $this->assertNull($result['depots'][0]['account_number']);
        $this->assertNull($result['depot_transactions'][0]['note']);
        $this->assertNull($result['stock_prices'][0]['raw_payload']);
        $this->assertSame('', $result['stock_prices'][0]['source_url']);
        $this->assertSame($result, (new PreviewSnapshotPolicy)->sanitize($result));
        $this->assertSame('private-account', $input['depots'][0]['account_number']);
    }

    #[DataProvider('excludedTables')]
    public function test_authentication_work_and_unknown_tables_cannot_enter_snapshot(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PreviewSnapshotPolicy)->sanitize([$table => []]);
    }

    public static function excludedTables(): array
    {
        return array_map(fn (string $table): array => [$table], [
            'users', 'roles', 'personal_access_tokens', 'sessions', 'password_reset_tokens', 'admin_login_codes',
            'jobs', 'failed_jobs', 'cache', 'app_configs', 'stock_ai_researches', 'agent_conversations', 'unknown',
        ]);
    }

    public function test_unexpected_credential_columns_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PreviewSnapshotPolicy)->sanitize(['depots' => [['id' => 1, 'api_token' => 'secret']]]);
    }

    public function test_only_exact_schema_grants_are_accepted(): void
    {
        (new PreviewDatabaseGuard)->validateGrants([
            'GRANT USAGE ON *.* TO `previewuser`@`localhost`',
            'GRANT ALL PRIVILEGES ON `preview123`.* TO `previewuser`@`localhost`',
        ], 'preview123');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeGrants')]
    public function test_global_other_schema_roles_and_grant_option_are_rejected(array $grants, string $database): void
    {
        $this->expectException(RuntimeException::class);
        (new PreviewDatabaseGuard)->validateGrants($grants, $database);
    }

    public static function unsafeGrants(): array
    {
        return [
            [[], 'preview123'],
            [['GRANT USAGE ON *.* TO `user`@`localhost`'], 'preview123'],
            [['GRANT SELECT ON *.* TO `user`@`localhost`'], 'preview123'],
            [['GRANT ALL PRIVILEGES ON `live123`.* TO `user`@`localhost`'], 'preview123'],
            [['GRANT ALL PRIVILEGES ON `preview123`.* TO `user`@`localhost` WITH GRANT OPTION'], 'preview123'],
            [['GRANT `adminrole`@`%` TO `user`@`localhost`'], 'preview123'],
            [['GRANT ALL PRIVILEGES ON `preview_123`.* TO `user`@`localhost`'], 'preview_123'],
        ];
    }
}
