<?php

namespace Tests\Concerns;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Banco limpo com perfis/permissões carregados e helpers de autenticação. */
trait InteractsWithIdentity
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    protected function userWithRole(Role $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes)->fresh();
    }

    protected function actingAsRole(Role $role, array $attributes = []): User
    {
        $user = $this->userWithRole($role, $attributes);
        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Troca o usuário autenticado no meio do teste. Os guards guardam o usuário
     * já resolvido (inclusive o do Sanctum), então são descartados antes.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->be($user, $guard);
    }

    /** Cabeçalhos de uma requisição vinda do SPA (origem "stateful" do Sanctum). */
    protected function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
    }
}
