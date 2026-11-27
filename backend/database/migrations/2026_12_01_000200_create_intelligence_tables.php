<?php

use App\Domain\Candidates\Search\CandidateSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7.9: base para IA. Texto extraído dos currículos (PDF, DOCX, OCR) e
 * sugestões geradas a partir dele, sempre sujeitas a revisão humana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->string('extraction_status', 20)->nullable();
            $table->string('extraction_method', 20)->nullable();
            $table->text('extracted_text')->nullable();
            $table->timestamp('extracted_at')->nullable();
        });

        Schema::create('candidate_suggestions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('value', 150);
            $table->decimal('confidence', 3, 2);
            $table->string('evidence', 300)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['candidate_id', 'type', 'value']);
            $table->index(['candidate_id', 'status']);
        });

        // Reindexa com a nova definição do índice (inclui o texto lido dos currículos).
        DB::statement(CandidateSearchIndex::SQL.' WHERE TRUE');
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_suggestions');
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->dropColumn(['extraction_status', 'extraction_method', 'extracted_text', 'extracted_at']);
        });
    }
};
