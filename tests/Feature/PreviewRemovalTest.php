<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PreviewRemovalTest extends TestCase
{
    public function test_preview_control_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('preview.control.show'));
        $this->assertFalse(Route::has('preview.control.update'));
        $this->assertFalse(Route::has('admin.preview.status'));
        $this->assertFalse(Route::has('admin.preview.control'));

        $this->get('/preview/control')->assertNotFound();
    }

    public function test_homepage_remains_public(): void
    {
        $this->get('/')->assertOk();
    }
}
