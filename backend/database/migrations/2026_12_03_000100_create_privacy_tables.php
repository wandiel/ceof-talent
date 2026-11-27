<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10: LGPD.
 * - privacy_requests: solicitações de titulares (acesso, correção, eliminação, portabilidade...).
 * - candidates.anonymized_at: marca o cadastro anonimizado (irreversível).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('protocol', 30)->unique();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name', 150);
            $table->string('requester_email', 191)->nullable();
            $table->string('requester_phone', 20)->nullable();
            $table->string('type', 30);
            $table->string('channel', 20);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->date('due_on');
            $table->text('response')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_on']);
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
        Schema::dropIfExists('privacy_requests');
    }
};
