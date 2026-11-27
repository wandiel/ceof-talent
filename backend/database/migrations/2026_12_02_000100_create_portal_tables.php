<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9: portal "Trabalhe conosco".
 * - portal_submissions: cada envio do portal, com protocolo e os dados como foram enviados.
 * - candidate_consents: consentimentos e ciência do aviso de privacidade (LGPD), com versão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('protocol', 30)->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_opening_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_new_candidate');
            $table->string('outcome', 30); // created | updated | applied | already_applied | candidate_inactive
            $table->jsonb('payload');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['candidate_id', 'created_at']);
        });

        Schema::create('candidate_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('version', 20);
            $table->string('channel', 20);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['candidate_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_consents');
        Schema::dropIfExists('portal_submissions');
    }
};
