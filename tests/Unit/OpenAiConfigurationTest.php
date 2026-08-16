<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class OpenAiConfigurationTest extends TestCase
{
    public function test_openai_provider_key_uses_project_environment_variable_name(): void
    {
        $fingerprint = $this->configuredKeyFingerprint([
            'OPENAI_API_KEY' => false,
            'OPEN_AI_KEY' => 'project-openai-key',
        ]);

        $this->assertSame(hash('sha256', 'project-openai-key'), $fingerprint);
    }

    public function test_openai_provider_key_prefers_standard_environment_variable_name(): void
    {
        $fingerprint = $this->configuredKeyFingerprint([
            'OPENAI_API_KEY' => 'standard-openai-key',
            'OPEN_AI_KEY' => 'project-openai-key',
        ]);

        $this->assertSame(hash('sha256', 'standard-openai-key'), $fingerprint);
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function configuredKeyFingerprint(array $environment): string
    {
        $script = <<<'PHP'
require $argv[1];
$config = require $argv[2];
echo hash('sha256', (string) $config['providers']['openai']['key']);
PHP;
        $process = new Process([
            PHP_BINARY,
            '-r',
            $script,
            base_path('vendor/autoload.php'),
            base_path('config/ai.php'),
        ], base_path(), $environment);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
