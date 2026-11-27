<?php

namespace Tests\Feature\Security;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Fase 11: testes de segurança transversais (controle de acesso, injeção,
 * exposição de dados e atribuição em massa).
 */
class SecurityTest extends TestCase
{
    use InteractsWithIdentity;

    /** Rotas que podem ser acessadas sem login. Qualquer rota nova fora desta lista exige autenticação. */
    private const PUBLIC_ROUTES = [
        'api/health',
        'api/v1/auth/login',
        'api/v1/auth/forgot-password',
        'api/v1/auth/reset-password',
        'api/v1/public/applications',
        'api/v1/public/cep/{cep}',
        'api/v1/public/jobs',
        'api/v1/public/jobs/{job}',
        'api/v1/public/options',
        'api/v1/public/privacy',
    ];

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/')));
    }

    private function concreteUri(RoutingRoute $route): string
    {
        // Parâmetros recebem um UUID qualquer (as rotas usam UUID, nunca ID sequencial).
        // Parâmetros com restrição (ex.: formato do relatório) recebem um valor válido.
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route) {
            $pattern = $route->wheres[$m[1]] ?? null;

            return match ($pattern) {
                null => (string) Str::uuid7(),
                '[a-z_]+' => 'candidates_general',
                default => explode('|', $pattern)[0],
            };
        }, $route->uri());
    }

    public function test_every_private_api_route_requires_authentication_and_active_user(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC_ROUTES, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "Rota sem autenticação: {$route->uri()}");
            $this->assertContains('active', $middleware, "Rota sem verificação de usuário ativo: {$route->uri()}");

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $status = $this->json($method, $this->concreteUri($route))->status();
            $this->assertSame(401, $status, "{$method} {$route->uri()} respondeu {$status} sem login");
            $checked++;
        }
        $this->assertGreaterThan(90, $checked);
    }

    public function test_public_routes_are_rate_limited(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (! in_array($route->uri(), self::PUBLIC_ROUTES, true)) {
                continue;
            }
            $throttled = collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle'));
            $this->assertTrue($throttled, "Rota pública sem limite de requisições: {$route->uri()}");
        }
    }

    /** @return array<string, array{string, string}> */
    public static function restrictedForInterviewer(): array
    {
        return [
            'listar usuários' => ['GET', '/api/v1/users'],
            'criar usuário' => ['POST', '/api/v1/users'],
            'auditoria' => ['GET', '/api/v1/audit-logs'],
            'LGPD visão geral' => ['GET', '/api/v1/privacy/overview'],
            'LGPD solicitações' => ['GET', '/api/v1/privacy/requests'],
            'relatórios' => ['GET', '/api/v1/reports'],
            'dashboard' => ['GET', '/api/v1/dashboard'],
            'criar candidato' => ['POST', '/api/v1/candidates'],
            'criar vaga' => ['POST', '/api/v1/jobs'],
            'criar unidade' => ['POST', '/api/v1/units'],
            'criar etiqueta' => ['POST', '/api/v1/tags'],
            'criar critério' => ['POST', '/api/v1/evaluation-criteria'],
            'duplicidades' => ['GET', '/api/v1/candidates/duplicates'],
        ];
    }

    #[DataProvider('restrictedForInterviewer')]
    public function test_interviewer_cannot_reach_administrative_endpoints(string $method, string $uri): void
    {
        $this->actingAsRole(Role::Interviewer);
        $this->json($method, $uri, [])->assertForbidden();
    }

    public function test_temporary_password_blocks_the_api_until_changed(): void
    {
        $this->actingAsRole(Role::Admin, ['must_change_password' => true]);
        $this->getJson('/api/v1/candidates')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_records_are_never_addressed_by_sequential_id(): void
    {
        $this->actingAsRole(Role::Admin);
        $candidate = Candidate::factory()->create();
        $this->getJson("/api/v1/candidates/{$candidate->id}")->assertNotFound();
        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertOk()->assertJsonMissingPath('data.uuid');
    }

    public function test_sql_injection_attempts_in_search_filters_and_sort_are_harmless(): void
    {
        $this->actingAsRole(Role::Admin);
        Candidate::factory()->count(3)->create();
        $payloads = ["' OR 1=1 --", "'; DROP TABLE candidates; --", '%_%', '\\', '1) UNION SELECT password FROM users --'];

        foreach ($payloads as $p) {
            $this->getJson('/api/v1/candidates?'.http_build_query(['search' => $p]))->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/users?'.http_build_query(['search' => $p]))->assertOk();
            $this->getJson('/api/v1/audit-logs?'.http_build_query(['search' => $p]))->assertOk();
            $this->getJson('/api/v1/jobs?'.http_build_query(['search' => $p]))->assertOk();
            $sort = $this->getJson('/api/v1/candidates?'.http_build_query(['sort' => $p]));
            $this->assertContains($sort->status(), [200, 422], "sort={$p} retornou {$sort->status()}");
        }
        $this->assertSame(3, Candidate::count());
    }

    public function test_password_hashes_and_tokens_never_leave_the_api(): void
    {
        $admin = $this->actingAsRole(Role::Admin);
        $other = $this->userWithRole(Role::Recruiter);

        foreach (['/api/v1/auth/me', '/api/v1/users', "/api/v1/users/{$other->uuid}", '/api/v1/audit-logs'] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString($admin->getAuthPassword(), $body, $uri);
            $this->assertStringNotContainsString('"password"', $body, $uri);
            $this->assertStringNotContainsString('remember_token', $body, $uri);
        }
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $this->actingAsRole(Role::Recruiter);
        $res = $this->postJson('/api/v1/candidates', [
            'full_name' => 'Teste Atribuição',
            'email' => 'teste.massa@exemplo.com',
            'phone' => '92988887777',
            'city' => 'Manaus',
            'state' => 'AM',
            'professional_area' => 'nursing',
            'education_level' => 'higher',
            'source' => 'referral',
            'id' => 999999,
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'anonymized_at' => now()->toDateTimeString(),
            'created_by' => 1,
        ])->assertCreated();

        $candidate = Candidate::where('email', 'teste.massa@exemplo.com')->firstOrFail();
        $this->assertNotSame(999999, $candidate->id);
        $this->assertNotSame('00000000-0000-0000-0000-000000000000', $candidate->uuid);
        $this->assertNull($candidate->anonymized_at);
        $this->assertSame($res->json('data.id'), $candidate->uuid);
    }

    public function test_users_cannot_grant_themselves_more_access(): void
    {
        $manager = $this->actingAsRole(Role::HrManager);
        $this->putJson("/api/v1/users/{$manager->uuid}", ['roles' => ['admin']])->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => 'Novo Admin', 'email' => 'novo@ceof.test', 'roles' => ['admin']])->assertForbidden();
        $this->assertFalse($manager->fresh()->hasRole('admin'));
        $this->assertSame(0, User::where('email', 'novo@ceof.test')->count());
    }

    public function test_server_errors_do_not_expose_internal_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/_test-boom', fn () => throw new \RuntimeException('segredo interno SQLSTATE'));

        $res = $this->getJson('/api/_test-boom')->assertStatus(500);
        $this->assertStringNotContainsString('segredo interno', $res->getContent());
        $this->assertStringNotContainsString('trace', $res->getContent());
    }

    public function test_html_in_user_content_is_escaped_in_pdf_reports(): void
    {
        $html = view('reports.pdf', [
            'report' => [
                'title' => 'Teste <i>x</i>',
                'description' => '<u>descrição</u>',
                'columns' => [['label' => 'Nome', 'type' => 'text'], ['label' => 'Cidade', 'type' => 'text']],
            ],
            'rows' => [['<script>alert(1)</script> Teste', '<b>Manaus</b>']],
            'filters' => [['label' => 'Período', 'value' => '<b>x</b>']],
            'generatedAt' => now()->format('d/m/Y H:i'),
            'generatedBy' => '<img src=x onerror=alert(1)>',
            'truncated' => false,
            'limit' => 3000,
            'logo' => null,
        ])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
