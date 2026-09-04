<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * The project ships no compiled asset bundle, so this page has to render
     * from its inlined stylesheet alone. An @vite call here would throw
     * "Vite manifest not found" and return a 500 instead.
     */
    public function test_the_welcome_page_renders_without_a_compiled_asset_bundle(): void
    {
        $response = $this->get('/welcome');

        $response->assertStatus(200);
        $response->assertDontSee('/build/assets', escape: false);
    }
}
