<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_homepage_renders_with_the_public_layout(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee(config('app.name'));
    }
}
