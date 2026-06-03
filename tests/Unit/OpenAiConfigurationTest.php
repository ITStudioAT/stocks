<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

class OpenAiConfigurationTest extends TestCase
{
    public function test_openai_provider_key_uses_project_environment_variable_name(): void
    {
        Env::getRepository()->clear('OPENAI_API_KEY');
        Env::getRepository()->set('OPEN_AI_KEY', 'project-openai-key');

        $config = require base_path('config/ai.php');

        $this->assertSame('project-openai-key', $config['providers']['openai']['key']);
    }

    public function test_openai_provider_key_prefers_standard_environment_variable_name(): void
    {
        Env::getRepository()->set('OPENAI_API_KEY', 'standard-openai-key');
        Env::getRepository()->set('OPEN_AI_KEY', 'project-openai-key');

        $config = require base_path('config/ai.php');

        $this->assertSame('standard-openai-key', $config['providers']['openai']['key']);
    }

    protected function tearDown(): void
    {
        Env::getRepository()->clear('OPENAI_API_KEY');
        Env::getRepository()->clear('OPEN_AI_KEY');

        parent::tearDown();
    }
}
