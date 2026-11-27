<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completa a tabela de usuários internos do sistema (equipe de RH e gestores).
 * Candidatos NÃO são usuários: terão tabela própria (Fase 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        // E-mail sem distinção de maiúsculas/minúsculas (extensão citext).
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        Schema::table('users', function (Blueprint $table) {
            $table->uuid()->nullable()->unique()->after('id');
            $table->boolean('is_active')->default(true)->index()->after('password');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->foreignId('unit_id')->nullable()->after('must_change_password')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('unit_id')->constrained()->restrictOnDelete();
            $table->string('job_title', 150)->nullable()->after('department_id');
            $table->string('phone', 30)->nullable()->after('job_title');
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->index('department_id');
            $table->index('unit_id');
        });

        DB::statement('UPDATE users SET uuid = gen_random_uuid() WHERE uuid IS NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN uuid SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'uuid', 'is_active', 'must_change_password', 'job_title', 'phone',
                'password_changed_at', 'last_login_at', 'last_login_ip',
            ]);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');
    }
};
