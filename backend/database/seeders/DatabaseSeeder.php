<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder padrão: carrega SOMENTE dados de referência (perfis e permissões).
 * O CEOF TALENT não cria usuários nem dados de negócio automaticamente.
 * O primeiro administrador é criado com: php artisan ceof:create-admin
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
