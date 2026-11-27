<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Anonimização definitiva (LGPD): remove o que identifica a pessoa e mantém só dados
 * estatísticos (área, escolaridade, cidade, origem, etapas e datas dos processos).
 *
 * Fica guardado: as inscrições e o histórico de etapas (sem observações), as notas dos
 * pareceres (sem textos), os registros de consentimento (sem IP) e a trilha de auditoria
 * (sem os valores alterados), para estatística e para comprovar o cumprimento da lei.
 */
class AnonymizeCandidate
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CandidateSearchIndex $searchIndex,
    ) {}

    public function handle(Candidate $candidate, string $reason, ?User $actor, ?PrivacyRequest $request = null, bool $force = false): void
    {
        if ($candidate->isAnonymized()) {
            throw ValidationException::withMessages(['candidate' => 'Este cadastro já foi anonimizado.']);
        }

        $open = $candidate->applications()->whereNotIn('stage', [ApplicationStage::Hired->value, ApplicationStage::Rejected->value, ApplicationStage::Withdrawn->value])->count();
        if ($open > 0 && ! $force) {
            throw ValidationException::withMessages(['candidate' => "O candidato tem {$open} ".($open === 1 ? 'processo seletivo em andamento' : 'processos seletivos em andamento').'. Mova para Desistente ou Reprovado antes de anonimizar.']);
        }

        $paths = [];
        DB::transaction(function () use ($candidate, $reason, $actor, $request, &$paths) {
            $id = $candidate->id;
            $applicationIds = DB::table('applications')->where('candidate_id', $id)->pluck('id');
            $interviewIds = DB::table('interviews')->whereIn('application_id', $applicationIds)->pluck('id');
            $evaluationIds = DB::table('interview_evaluations')->whereIn('interview_id', $interviewIds)->pluck('id');

            // Arquivos e conteúdo livre
            $paths = DB::table('candidate_documents')->where('candidate_id', $id)->pluck('path')->all();
            DB::table('candidate_suggestions')->where('candidate_id', $id)->delete();
            DB::table('candidate_documents')->where('candidate_id', $id)->delete();
            foreach (['candidate_educations', 'candidate_experiences', 'candidate_courses', 'candidate_notes', 'candidate_skill', 'candidate_tag', 'candidate_favorites', 'candidate_duplicate_dismissals'] as $table) {
                DB::table($table)->where('candidate_id', $id)->delete();
            }
            DB::table('candidate_duplicate_dismissals')->where('other_candidate_id', $id)->delete();

            // Processos: mantém etapas e notas, sem textos livres que possam identificar
            DB::table('application_stage_history')->whereIn('application_id', $applicationIds)->update(['note' => null]);
            DB::table('interviews')->whereIn('id', $interviewIds)->update(['instructions' => null, 'status_reason' => null]);
            DB::table('interview_evaluations')->whereIn('id', $evaluationIds)->update(['summary' => 'Texto removido na anonimização (LGPD).', 'observations' => null]);
            DB::table('interview_evaluation_scores')->whereIn('interview_evaluation_id', $evaluationIds)->update(['comment' => null]);

            // Prova de consentimento e envios do portal sem dados pessoais
            DB::table('candidate_consents')->where('candidate_id', $id)->update(['ip_address' => null, 'user_agent' => null]);
            DB::table('portal_submissions')->where('candidate_id', $id)->update(['payload' => json_encode(['anonymized' => true]), 'ip_address' => null, 'user_agent' => null]);

            // Trilha de auditoria: mantém quem fez o quê e quando, sem os valores alterados
            DB::table('audit_logs')->where('auditable_type', $candidate->getMorphClass())->where('auditable_id', $id)
                ->update(['old_values' => null, 'new_values' => null]);

            // Cadastro
            DB::table('candidates')->where('id', $id)->update([
                'full_name' => 'Titular anonimizado #'.$id,
                'social_name' => null, 'email' => null, 'phone' => null, 'phone_secondary' => null,
                'cpf' => null, 'cpf_hash' => null, 'birth_date' => null,
                'linkedin_url' => null, 'portfolio_url' => null, 'zip_code' => null, 'neighborhood' => null,
                'desired_position' => null, 'summary' => null, 'salary_expectation' => null, 'availability' => null,
                'work_shifts' => '[]', 'willing_to_relocate' => false, 'source_detail' => null,
                'status' => CandidateStatus::Inactive->value,
                'anonymized_at' => now(), 'updated_at' => now(), 'updated_by' => $actor?->id,
            ]);
            $this->searchIndex->refresh($id);

            $this->audit->log('candidate.anonymized', $candidate, 'Cadastro anonimizado. Motivo: '.$reason, context: [
                'reason' => $reason,
                'request' => $request?->protocol,
                'documents_removed' => count($paths),
            ], userId: $actor?->id);
        });

        // Arquivos só saem do disco depois que o banco confirmou.
        foreach ($paths as $path) {
            Storage::disk(CandidateDocument::DISK)->delete($path);
        }
        Storage::disk(CandidateDocument::DISK)->deleteDirectory('candidates/'.$candidate->uuid);
    }
}
