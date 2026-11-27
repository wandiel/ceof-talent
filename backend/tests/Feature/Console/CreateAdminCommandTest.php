<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_installation_creates_no_users_by_default(): void
    {
        $this->assertSame(0, User::count());
    }

    public function test_command_creates_an_active_admin(): void
    {
        $this->artisan('ceof:create-admin', ['--name' => 'Admin CEOF', '--email' => 'admin@ceof.test', '--password' => 'Admin@Seguro2026'])
            ->expectsOutputToContain('Administrador pronto')
            ->assertSuccessful();

        $user = User::where('email', 'admin@ceof.test')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Admin@Seguro2026', $user->password));
        $this->assertTrue($user->can('users.manage'));
    }

    public function test_command_rejects_weak_password(): void
    {
        $this->artisan('ceof:create-admin', ['--name' => 'Admin', '--email' => 'admin@ceof.test', '--password' => '123'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
