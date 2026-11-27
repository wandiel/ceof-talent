<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_user_changes_own_password_and_temporary_flag_is_cleared(): void
    {
        $user = $this->actingAsRole(Role::Recruiter, ['password' => 'Antiga@Senha1', 'must_change_password' => true]);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'Antiga@Senha1',
            'password' => 'Nova@Senha2026',
            'password_confirmation' => 'Nova@Senha2026',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Nova@Senha2026', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed', 'user_id' => $user->id]);
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $this->actingAsRole(Role::Recruiter, ['password' => 'Antiga@Senha1']);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'errada',
            'password' => 'Nova@Senha2026',
            'password_confirmation' => 'Nova@Senha2026',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_password_policy_rejects_weak_passwords(): void
    {
        $this->actingAsRole(Role::Recruiter, ['password' => 'Antiga@Senha1']);

        $response = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'Antiga@Senha1',
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ])->assertStatus(422);

        $messages = implode(' ', $response->json('errors.password'));
        $this->assertStringContainsString('pelo menos 10 caracteres', $messages);
        $this->assertStringContainsString('maiúsculas e minúsculas', $messages);
    }

    public function test_forgot_password_sends_link_without_revealing_whether_email_exists(): void
    {
        Notification::fake();
        $user = $this->userWithRole(Role::HrAnalyst, ['email' => 'analista@ceof.test']);

        $known = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'analista@ceof.test'])->assertOk();
        $unknown = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'nao.existe@ceof.test'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_reset_link_points_to_frontend_page(): void
    {
        $user = $this->userWithRole(Role::HrAnalyst);
        $mail = (new ResetPasswordNotification('token-123'))->toMail($user);

        $this->assertStringStartsWith('http://localhost/redefinir-senha?token=token-123&email=', $mail->actionUrl);
    }

    public function test_user_resets_password_with_valid_token(): void
    {
        $user = $this->userWithRole(Role::HrAnalyst, ['must_change_password' => true]);
        $token = Password::broker()->createToken($user);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Redefinida@2026',
            'password_confirmation' => 'Redefinida@2026',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Redefinida@2026', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_reset_with_invalid_token_fails(): void
    {
        $user = $this->userWithRole(Role::HrAnalyst);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalido',
            'email' => $user->email,
            'password' => 'Redefinida@2026',
            'password_confirmation' => 'Redefinida@2026',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'O link de redefinição de senha é inválido ou expirou. Solicite um novo.');
    }
}
