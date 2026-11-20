<?php

namespace Tests\Feature\Listeners;

use Tests\TestCase;

/**
 * The /up route that an uptime monitor polls (T12.4). With debug mode on, Laravel rethrows the
 * health check's exception instead of answering 500, so these tests run with it off, as production does.
 */
class CheckDatabaseOnHealthRouteTest extends TestCase
{
    public function test_the_health_route_is_up_when_the_database_answers(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
    }

    public function test_the_health_route_is_down_when_the_database_cannot_be_reached(): void
    {
        config([
            'app.debug' => false,
            'database.connections.unreachable' => [...config('database.connections.mysql'), 'host' => '127.0.0.1', 'port' => 1],
            'database.default' => 'unreachable',
        ]);

        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);
    }
}
