<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4: cadastro de candidatos.
 *
 * - CPF é opcional, gravado criptografado (cast "encrypted") e acompanhado de um
 *   HMAC determinístico (cpf_hash) usado apenas para detectar duplicidade.
 * - E-mail e CPF são únicos entre candidatos não excluídos (índices parciais).
 * - Documentos ficam em disco privado; a tabela guarda só metadados e o SHA-256.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('full_name', 150);
            $table->string('social_name', 150)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_secondary', 20)->nullable();
            $table->text('cpf')->nullable();
            $table->char('cpf_hash', 64)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->char('zip_code', 8)->nullable();
            $table->string('neighborhood', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('professional_area', 40)->index();
            $table->string('education_level', 40)->index();
            $table->string('desired_position', 150)->nullable();
            $table->text('summary')->nullable();
            $table->decimal('salary_expectation', 10, 2)->nullable();
            $table->string('availability', 20)->nullable();
            $table->jsonb('work_shifts')->default('[]');
            $table->boolean('willing_to_relocate')->default(false);
            $table->string('source', 30)->index();
            $table->string('source_detail', 150)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['state', 'city']);
        });

        DB::statement('ALTER TABLE candidates ALTER COLUMN email TYPE citext');
        DB::statement('CREATE UNIQUE INDEX candidates_email_unique ON candidates (email) WHERE deleted_at IS NULL AND email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX candidates_cpf_hash_unique ON candidates (cpf_hash) WHERE deleted_at IS NULL AND cpf_hash IS NOT NULL');
        DB::statement('CREATE INDEX candidates_phone_index ON candidates (phone) WHERE phone IS NOT NULL');

        Schema::create('candidate_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('level', 40);
            $table->string('institution', 150);
            $table->string('course', 150)->nullable();
            $table->string('status', 20);
            $table->smallInteger('start_year')->nullable();
            $table->smallInteger('end_year')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('company', 150);
            $table->string('job_title', 150);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 150);
            $table->string('institution', 150)->nullable();
            $table->smallInteger('year')->nullable();
            $table->smallInteger('workload_hours')->nullable();
            $table->string('credential', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE skills ALTER COLUMN name TYPE citext');
        DB::statement('CREATE UNIQUE INDEX skills_name_unique ON skills (name)');

        Schema::create('candidate_skill', function (Blueprint $table) {
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->string('level', 20)->nullable();
            $table->primary(['candidate_id', 'skill_id']);
            $table->index('skill_id');
        });

        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('original_name', 255);
            $table->string('path', 255)->unique();
            $table->string('mime_type', 120);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['candidate_id', 'sha256']);
        });

        Schema::create('candidate_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['candidate_id', 'created_at']);
        });

        foreach (['candidate_educations', 'candidate_experiences', 'candidate_courses'] as $child) {
            DB::statement("CREATE INDEX {$child}_candidate_id_index ON {$child} (candidate_id)");
        }
    }

    public function down(): void
    {
        foreach (['candidate_notes', 'candidate_documents', 'candidate_skill', 'skills', 'candidate_courses', 'candidate_experiences', 'candidate_educations', 'candidates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
