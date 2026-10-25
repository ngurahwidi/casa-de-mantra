<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_application_uses_utc_timezone_and_english_locale(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('en', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }
}
