<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5: banco de currículos.
 *
 * - search_vector: índice de texto completo (português, sem acentos) com pesos:
 *   A nome e cargo pretendido; B competências e cargos ocupados;
 *   C resumo, empresas, atividades, formação e cursos; D cidade e bairro.
 *   É recalculado pela aplicação (CandidateSearchIndex) a cada alteração.
 * - Índices trigram para encontrar nomes parecidos (duplicidades).
 * - Favoritos são pessoais; etiquetas são compartilhadas pela equipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE candidates ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX candidates_search_vector_index ON candidates USING gin (search_vector)');
        DB::statement('CREATE INDEX candidates_full_name_trgm_index ON candidates USING gin (lower(full_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX candidates_phone_secondary_index ON candidates (phone_secondary) WHERE phone_secondary IS NOT NULL');
        DB::statement('CREATE INDEX candidate_experiences_job_title_trgm_index ON candidate_experiences USING gin (lower(job_title) gin_trgm_ops)');
        DB::statement('CREATE INDEX candidate_educations_course_trgm_index ON candidate_educations USING gin (lower(course) gin_trgm_ops)');

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 50);
            $table->string('color', 20)->default('slate');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE tags ALTER COLUMN name TYPE citext');
        DB::statement('CREATE UNIQUE INDEX tags_name_unique ON tags (name)');

        Schema::create('candidate_tag', function (Blueprint $table) {
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['candidate_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('candidate_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'candidate_id']);
            $table->index('candidate_id');
        });

        // Pares revisados pelo RH e marcados como "não são a mesma pessoa".
        Schema::create('candidate_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('other_candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['candidate_id', 'other_candidate_id']);
            $table->index('other_candidate_id');
        });
        DB::statement('ALTER TABLE candidate_duplicate_dismissals ADD CONSTRAINT candidate_duplicate_dismissals_order CHECK (candidate_id < other_candidate_id)');

        // Indexa os candidatos já cadastrados.
        DB::statement(App\Domain\Candidates\Search\CandidateSearchIndex::SQL.' WHERE TRUE');
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_duplicate_dismissals');
        Schema::dropIfExists('candidate_favorites');
        Schema::dropIfExists('candidate_tag');
        Schema::dropIfExists('tags');
        DB::statement('DROP INDEX IF EXISTS candidate_educations_course_trgm_index');
        DB::statement('DROP INDEX IF EXISTS candidate_experiences_job_title_trgm_index');
        DB::statement('DROP INDEX IF EXISTS candidates_phone_secondary_index');
        DB::statement('DROP INDEX IF EXISTS candidates_full_name_trgm_index');
        DB::statement('ALTER TABLE candidates DROP COLUMN IF EXISTS search_vector');
    }
};
