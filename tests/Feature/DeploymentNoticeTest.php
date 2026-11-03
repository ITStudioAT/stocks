<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentNoticeTest extends TestCase
{
    public function test_home_and_admin_login_include_the_deployment_notice(): void
    {
        foreach (['homepage', 'admin.login'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('data-deployment-notice', false)
                ->assertSee('data-state="idle"', false)
                ->assertSee('src="/deployment-notice.js"', false);
        }
    }

    public function test_the_prerendered_maintenance_page_loads_without_vite_assets(): void
    {
        $html = view('maintenance')->render();

        $this->assertStringContainsString('data-state="maintenance"', $html);
        $this->assertStringContainsString('Stocks wird aktualisiert', $html);
        $this->assertStringContainsString('src="/deployment-notice.js"', $html);
        $this->assertStringContainsString('href="/deployment-notice.css"', $html);
        $this->assertStringNotContainsString('/build/assets/', $html);
    }
}
