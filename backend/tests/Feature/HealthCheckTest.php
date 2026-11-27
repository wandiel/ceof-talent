<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_all_infrastructure_components_as_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.database.driver', 'pgsql')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.storage.status', 'ok');
    }

    public function test_tests_never_run_against_the_main_database(): void
    {
        $this->assertSame('ceof_talent_test', config('database.connections.pgsql.database'));
    }

    public function test_laravel_liveness_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
