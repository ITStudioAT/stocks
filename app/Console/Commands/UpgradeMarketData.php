<?php

namespace App\Console\Commands;

use App\Services\EodhdErrorSanitizer;
use App\Services\MarketDataMode;
use App\Services\MarketDataUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class UpgradeMarketData extends Command
{
    protected $signature = 'market-data:upgrade {--deployment} {--prepare} {--finish} {--rollback} {--rollback-if-pending} {--restore=} {--confirm-database=}';

    protected $description = 'Controlled deployment data upgrade with native backup, write barriers, verification and durable activation';

    public function handle(MarketDataUpgrade $upgrade): int
    {
        set_time_limit(0);
        $deployment = (bool) $this->option('deployment');
        $upgrade->assertContext($deployment);
        $path = app(MarketDataMode::class)->path().'.upgrade.lock';
        File::ensureDirectoryExists(dirname($path), 0700);
        $lock = fopen($path, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('Another market-data upgrade is running.');

            return self::FAILURE;
        }
        try {
            if ($this->option('restore')) {
                $upgrade->restore($this->option('restore'), (string) $this->option('confirm-database'), $deployment);
            } elseif ($this->option('rollback-if-pending')) {
                if (app(MarketDataMode::class)->paused()) {
                    $upgrade->rollback($deployment);
                }
            } elseif ($this->option('rollback')) {
                $upgrade->rollback($deployment);
            } elseif ($this->option('prepare')) {
                $upgrade->prepare($deployment);
            } elseif ($this->option('finish')) {
                $this->line(json_encode($upgrade->finish($deployment), JSON_THROW_ON_ERROR));
            } else {
                $this->error('Select --prepare, --finish, --rollback or --restore.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $this->option('restore')) {
                $upgrade->rollback($deployment);
            }
            $this->error(app(EodhdErrorSanitizer::class)->message($exception->getMessage(), 255));

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
