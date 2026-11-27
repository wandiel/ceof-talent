<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 7: entrevistas, entrevistadores, critérios de avaliação e pareceres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX evaluation_criteria_name_unique ON evaluation_criteria (lower(name))');

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('mode', 20);
            $table->timestampTz('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('location', 255)->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->text('instructions')->nullable();
            // Critérios copiados no agendamento: renomear ou desativar um critério não altera entrevistas já marcadas.
            $table->jsonb('criteria');
            $table->string('status', 20)->default('scheduled');
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('application_id');
        });
        DB::statement('ALTER TABLE interviews ADD CONSTRAINT interviews_duration_check CHECK (duration_minutes BETWEEN 10 AND 480)');

        Schema::create('interview_interviewer', function (Blueprint $table) {
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['interview_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('interview_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('recommendation', 30);
            $table->text('summary');
            $table->text('observations')->nullable();
            $table->decimal('average_score', 3, 2)->nullable();
            $table->timestamps();
            $table->unique(['interview_id', 'user_id']);
        });

        Schema::create('interview_evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_evaluation_id')->constrained()->cascadeOnDelete();
            $table->uuid('criterion_uuid');
            $table->string('criterion_name', 100);
            $table->unsignedSmallInteger('score');
            $table->string('comment', 500)->nullable();
            $table->unique(['interview_evaluation_id', 'criterion_uuid']);
        });
        DB::statement('ALTER TABLE interview_evaluation_scores ADD CONSTRAINT interview_scores_range_check CHECK (score BETWEEN 1 AND 5)');

        $now = now();
        $defaults = [
            ['Comunicação', 'Clareza, objetividade e escuta.'],
            ['Conhecimento técnico', 'Domínio das atividades e requisitos da vaga.'],
            ['Experiência relevante', 'Vivências anteriores aplicáveis à função.'],
            ['Trabalho em equipe', 'Colaboração, respeito e relacionamento.'],
            ['Postura profissional', 'Ética, pontualidade e comprometimento.'],
            ['Foco no paciente', 'Empatia, cuidado e atenção à segurança do paciente.'],
        ];
        foreach ($defaults as $i => [$name, $description]) {
            DB::table('evaluation_criteria')->insert([
                'uuid' => (string) Str::uuid(), 'name' => $name, 'description' => $description,
                'is_active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['interview_evaluation_scores', 'interview_evaluations', 'interview_interviewer', 'interviews', 'evaluation_criteria'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
