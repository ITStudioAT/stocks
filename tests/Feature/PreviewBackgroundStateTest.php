<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Services\PreviewBackgroundState;
use App\Services\PreviewIsolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PreviewBackgroundStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_processing_is_off_until_explicitly_enabled(): void
    {
        config()->set('security.preview.control_enabled', true);
        $isolation = Mockery::mock(PreviewIsolation::class);
        $isolation->shouldReceive('active')->andReturn(true);
        $isolation->shouldReceive('problems')->once()->andReturn([]);
        $state = new PreviewBackgroundState($isolation);

        $this->assertFalse($state->enabled());
        $state->setEnabled(true);
        $this->assertTrue($state->enabled());
        $this->assertTrue(AppConfig::query()->where('key', 'preview.background.enabled')->exists());
        $state->setEnabled(false);
        $this->assertFalse($state->enabled());
    }

    public function test_live_application_cannot_change_preview_processing_state(): void
    {
        $state = app(PreviewBackgroundState::class);

        $this->assertFalse($state->enabled());
        $this->expectException(RuntimeException::class);
        $state->setEnabled(true);
    }
}
