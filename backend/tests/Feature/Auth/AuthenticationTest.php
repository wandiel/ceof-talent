<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\Role;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithIdentity;

    private const PASSWORD = 'Senha@Forte123';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:recrutadora@ceof.test|127.0.0.1');
    }

    private function makeUser(array $attributes = []): User
    {
        return $this->userWithRole(Role::Recruiter, ['email' => 'recrutadora@ceof.test', 'password' => self::PASSWORD] + $attributes);
    }

    public function test_user_logs_in_with_valid_credentials_and_receives_profile_and_permissions(): void
    {
        $user = $this->makeUser();

        $response = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'RECRUTADORA@ceof.test', 'password' => self::PASSWORD]);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->uuid)
            ->assertJsonPath('data.roles.0.name', 'recruiter')
            ->assertJsonPath('data.roles.0.label', 'Recrutador')
            ->assertJsonMissingPath('data.password');
        $this->assertContains('candidates.view', $response->json('data.permissions'));
        $this->assertNotContains('users.manage', $response->json('data.permissions'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_invalid_password_is_rejected_with_generic_message_and_audited(): void
    {
        $this->makeUser();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'recrutadora@ceof.test', 'password' => 'errada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');

        $this->assertGuest('web');
        $this->assertSame(1, AuditLog::where('event', 'auth.login_failed')->count());
    }

    public function test_unknown_email_gets_the_same_generic_message(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ninguem@ceof.test', 'password' => 'qualquer'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'recrutadora@ceof.test', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Seu acesso está desativado. Procure o administrador do sistema.');

        $this->assertGuest('web');
    }

    public function test_login_is_blocked_after_too_many_failed_attempts(): void
    {
        $this->makeUser();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'recrutadora@ceof.test', 'password' => 'errada'])->assertStatus(422);
        }

        // Mesmo com a senha correta, o bloqueio temporário se mantém.
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'recrutadora@ceof.test', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('errors.email.0', fn (string $message) => str_starts_with($message, 'Muitas tentativas de acesso'));

        $this->assertGuest('web');
    }

    public function test_login_validates_required_fields(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_own_profile(): void
    {
        $user = $this->actingAsRole(Role::HrManager);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0.name', 'hr_manager');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->makeUser();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        $this->spa()->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_user_deactivated_during_session_is_logged_out_on_next_request(): void
    {
        $user = $this->actingAsRole(Role::HrAnalyst);
        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_user_with_temporary_password_must_change_it_before_using_the_system(): void
    {
        $this->actingAsRole(Role::Admin, ['must_change_password' => true]);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }
}
