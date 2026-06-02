<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_homepage_route_renders_the_homepage_view(): void
    {
        $response = $this->get(route('homepage'));

        $response->assertStatus(200);
        $response->assertViewIs('homepage');
        $response->assertSee('id="homepage"', false);
    }

    public function test_homepage_loads_the_editorial_google_fonts(): void
    {
        $response = $this->get(route('homepage'));

        $response->assertStatus(200);
        $response->assertSee('Newsreader', false);
        $response->assertSee('IBM+Plex+Mono', false);
    }
}
