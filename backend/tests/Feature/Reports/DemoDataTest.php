<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Enums\Role;
use App\Models\Application;
use App\Models\Candidate;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_generates_demo_data_once_and_dashboard_uses_it(): void
    {
        $this->userWithRole(Role::Admin);
        $this->artisan('ceof:demo-data')->assertSuccessful();
        $this->assertSame(90, Candidate::count());
        $this->assertGreaterThan(30, Application::count());

        $this->artisan('ceof:demo-data')->assertFailed();

        $this->actingAsRole(Role::HrManager);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.kpis.total_candidates', 90);
    }

    public function test_refuses_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('ceof:demo-data')->assertFailed();
        $this->assertSame(0, Candidate::count());
    }
}
