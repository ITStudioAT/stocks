<?php

namespace App\Console\Commands;

use App\Services\PreviewIsolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('preview:check')]
#[Description('Check preview configuration without connecting to a database or service')]
class PreviewCheckCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PreviewIsolation $isolation): int
    {
        foreach ($problems = $isolation->problems() as $problem) {
            $this->error('Check required: '.$problem);
        }
        $this->line('Configuration check only. Server ownership, database grants, backup/restore and deployment remain separate gates.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
