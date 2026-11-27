<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\PasswordPolicy;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Cria (ou promove) um usuário Administrador. É a única forma de criar o
 * primeiro acesso ao sistema: nada é criado automaticamente na instalação.
 *
 * Uso interativo:  php artisan ceof:create-admin
 * Não interativo:  php artisan ceof:create-admin --name="Maria" --email=maria@empresa.com --password="..."
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'ceof:create-admin
        {--name= : Nome completo}
        {--email= : E-mail de acesso}
        {--password= : Senha (se omitida, será solicitada sem exibição)}';

    protected $description = 'Cria um usuário Administrador do CEOF TALENT';

    public function handle(AuditLogger $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Nome completo');
        $email = $this->option('email') ?: $this->ask('E-mail de acesso');
        $password = $this->option('password');

        if (! $password) {
            $password = $this->secret('Senha (mín. '.PasswordPolicy::MIN_LENGTH.' caracteres, com maiúsculas, minúsculas, números e símbolos)');
            $confirmation = $this->secret('Confirme a senha');

            if ($password !== $confirmation) {
                $this->error('As senhas não conferem.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', PasswordPolicy::rule()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Garante que os perfis existam mesmo se o seeder ainda não tiver rodado.
        (new ReferenceDataSeeder)->run();

        $user = DB::transaction(function () use ($name, $email, $password, $audit) {
            $user = User::query()->where('email', $email)->first();
            $created = $user === null;

            $user ??= new User(['email' => $email]);
            $user->fill(['name' => $name, 'password' => $password, 'is_active' => true, 'must_change_password' => false]);
            $user->password_changed_at = now();
            $user->save();
            $user->assignRole(Role::Admin->value);

            $audit->log(
                $created ? 'user.created' : 'user.promoted_admin',
                $user,
                'Administrador definido via linha de comando (ceof:create-admin).',
                new: ['name' => $user->name, 'email' => $user->email, 'roles' => [Role::Admin->value]],
            );

            return $user;
        });

        $this->info("Administrador pronto: {$user->name} <{$user->email}>");

        return self::SUCCESS;
    }
}
