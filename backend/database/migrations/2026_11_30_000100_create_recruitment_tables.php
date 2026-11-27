<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6: vagas e processos seletivos.
 *
 * A tabela se chama job_openings porque "jobs" é a fila do Laravel.
 * Históricos (status da vaga e etapas da inscrição) só recebem inserções.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 20)->unique();
            $table->string('title', 150);
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('professional_area', 40)->index();
            $table->string('employment_type', 20);
            $table->string('reason', 20);
            $table->string('replaced_employee', 150)->nullable();
            $table->unsignedSmallInteger('positions_count')->default(1);
            $table->string('work_schedule', 150)->nullable();
            $table->decimal('salary_min', 10, 2)->nullable();
            $table->decimal('salary_max', 10, 2)->nullable();
            $table->boolean('show_salary')->default(false);
            $table->string('education_level_min', 40)->nullable();
            $table->text('description');
            $table->text('responsibilities')->nullable();
            $table->text('benefits')->nullable();
            $table->boolean('is_public')->default(true);
            $table->date('closes_on')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->index('unit_id');
            $table->index('position_id');
            $table->index('requested_by');
        });
        DB::statement('ALTER TABLE job_openings ADD CONSTRAINT job_openings_positions_check CHECK (positions_count BETWEEN 1 AND 500)');
        DB::statement('ALTER TABLE job_openings ADD CONSTRAINT job_openings_salary_check CHECK (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max)');

        Schema::create('job_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->index(['job_opening_id', 'sort']);
        });

        Schema::create('job_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['job_opening_id', 'id']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('job_opening_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->string('stage', 20)->default('applied');
            $table->string('previous_stage', 20)->nullable();
            $table->string('rejection_reason', 30)->nullable();
            $table->string('source', 30)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stage_changed_at')->useCurrent();
            $table->timestamp('hired_at')->nullable();
            $table->timestamps();

            $table->unique(['job_opening_id', 'candidate_id']);
            $table->index(['job_opening_id', 'stage']);
            $table->index('candidate_id');
        });

        Schema::create('application_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20);
            $table->string('reason', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['application_id', 'id']);
        });

        foreach (['job_status_history', 'application_stage_history'] as $table) {
            DB::statement("CREATE RULE {$table}_no_update AS ON UPDATE TO {$table} DO INSTEAD NOTHING");
        }
    }

    public function down(): void
    {
        foreach (['application_stage_history', 'applications', 'job_status_history', 'job_requirements', 'job_openings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
