<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura organizacional: unidades, departamentos e cargos.
 * Usada para escopo de acesso (gestor de departamento) e, nas próximas
 * fases, por vagas, processos seletivos e relatórios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('state', 2)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique('name');
            $table->unique('code');
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique('name');
            $table->unique('code');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->string('cbo_code', 10)->nullable()->comment('Classificação Brasileira de Ocupações');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('department_id');
        });

        // Título único por departamento (inclusive cargos sem departamento).
        DB::statement('CREATE UNIQUE INDEX positions_department_title_unique ON positions (COALESCE(department_id, 0), lower(title))');
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('units');
    }
};
