<?php

namespace Tests\Feature;

use App\Console\Commands\PrepareLocalDevelopment;
use Tests\TestCase;

class LocalDevelopmentPreparationTest extends TestCase
{
    public function test_remote_and_ambiguous_connections_are_rejected_before_migration(): void
    {
        $this->app['env'] = 'local';
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => 'example.org',
            'database.connections.mysql.database' => 'stocks']);
        $this->artisan('app:prepare-local-development')->assertFailed();

        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.url' => 'mysql://remote/stocks']);
        $this->artisan('app:prepare-local-development')->assertFailed();
    }

    public function test_destructive_and_data_changing_sql_is_rejected(): void
    {
        foreach ([' ⇂ drop table `stocks`', ' ⇂ alter table `stocks` drop column `price`', ' ⇂ delete from `stocks`', ' ⇂ update `stocks` set price = 0'] as $sql) {
            $this->assertTrue(PrepareLocalDevelopment::containsUnsafeSql($sql), $sql);
        }
        $this->assertFalse(PrepareLocalDevelopment::containsUnsafeSql(" ⇂ create table `new_table` (`id` int)\n ⇂ alter table `new_table` add `name` varchar(100)"));
    }
}
