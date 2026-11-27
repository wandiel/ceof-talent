<?php

namespace App\Domain\Portal\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Documents\StoreCandidateDocument;
use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\DocumentType;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Candidates\Support\Cpf;
use App\Domain\Portal\Enums\ConsentPurpose;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\PortalSubmission;
use App\Notifications\PortalSubmissionReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o envio do portal "Trabalhe conosco".
 *
 * - Pessoa nova: cria o cadastro com origem "Portal".
 * - Pessoa já cadastrada (mesmo e-mail ou CPF): NÃO altera o cadastro (quem envia pode não ser o
 *   dono do e-mail); guarda os dados enviados no protocolo e avisa no histórico o que difere.
 * - Anexa o currículo (lido automaticamente) e os documentos, inscreve na vaga quando houver,
 *   registra o aceite do aviso de privacidade e o consentimento do banco de talentos, e manda
 *   o comprovante por e-mail.
 *
 * A resposta é sempre a mesma para o visitante, para não revelar se o e-mail já existia.
 */
class SubmitPortalApplication
{
    private const PROFILE_FIELDS = ['full_name', 'social_name', 'email', 'phone', 'birth_date', 'zip_code', 'neighborhood', 'city', 'state', 'professional_area', 'education_level', 'desired_position', 'summary', 'linkedin_url'];

    private const LABELS = ['full_name' => 'nome', 'social_name' => 'nome social', 'phone' => 'telefone', 'birth_date' => 'nascimento', 'zip_code' => 'CEP', 'neighborhood' => 'bairro', 'city' => 'cidade', 'state' => 'UF', 'professional_area' => 'área', 'education_level' => 'escolaridade', 'desired_position' => 'cargo pretendido', 'linkedin_url' => 'LinkedIn', 'cpf' => 'CPF'];

    public function __construct(
        private readonly StoreCandidateDocument $documents,
        private readonly AuditLogger $audit,
        private readonly CandidateSearchIndex $searchIndex,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validado por PortalApplicationRequest
     * @param  list<UploadedFile>  $extraDocuments
     */
    public function handle(array $data, UploadedFile $resume, array $extraDocuments, ?string $ip, ?string $userAgent): PortalSubmission
    {
        $job = isset($data['job']) ? JobOpening::query()->publiclyOpen()->where('uuid', $data['job'])->first() : null;
        if (isset($data['job']) && ! $job) {
            throw ValidationException::withMessages(['job' => 'Esta vaga não está mais recebendo inscrições. Você pode enviar o currículo para o banco de talentos.']);
        }

        $submission = DB::transaction(function () use ($data, $resume, $extraDocuments, $ip, $userAgent, $job) {
            $existing = $this->findExisting($data);
            $candidate = $existing ?? $this->create($data);
            $outcome = $existing ? 'updated' : 'created';

            // Arquivos: o currículo repetido (mesmo arquivo) não é erro para o visitante.
            $this->attach($candidate, $resume, DocumentType::Resume);
            foreach ($extraDocuments as $file) {
                $this->attach($candidate, $file, DocumentType::Other);
            }

            $application = null;
            if ($job) {
                [$application, $outcome] = $this->apply($candidate, $job, $outcome);
            }

            $this->consent($candidate, ConsentPurpose::PrivacyNotice, $ip, $userAgent);
            if (! empty($data['talent_pool_consent'])) {
                $this->consent($candidate, ConsentPurpose::TalentPool, $ip, $userAgent);
            }

            $submission = PortalSubmission::create([
                'protocol' => $this->protocol(),
                'candidate_id' => $candidate->id,
                'job_opening_id' => $job?->id,
                'application_id' => $application?->id,
                'is_new_candidate' => ! $existing,
                'outcome' => $outcome,
                'payload' => Arr::except($data, ['cpf', 'privacy_accepted', 'website']) + ['cpf_informed' => ! empty($data['cpf'])],
                'ip_address' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
            ]);

            $where = $job ? "para a vaga {$job->code} · {$job->title}" : 'para o banco de talentos';
            $diff = $existing ? $this->differences($existing, $data) : [];
            $text = "Envio pelo portal {$where} (protocolo {$submission->protocol}).";
            if ($diff) {
                $text .= ' Dados informados diferentes do cadastro: '.implode(', ', $diff).'.';
            }
            if ($outcome === 'candidate_inactive') {
                $text .= ' Cadastro inativo: a inscrição não foi feita automaticamente.';
            }
            $this->audit->log('candidate.portal_submission', $candidate, $text, context: ['protocol' => $submission->protocol, 'job' => $job?->uuid, 'outcome' => $outcome], userId: null);

            $this->searchIndex->refresh($candidate->id);

            return $submission;
        });

        Notification::route('mail', [$data['email'] => $data['full_name']])
            ->notify(new PortalSubmissionReceived($submission->protocol, $data['social_name'] ?? $data['full_name'], $job?->title, $job?->code));

        return $submission;
    }

    private function findExisting(array $data): ?Candidate
    {
        $cpfHash = ! empty($data['cpf']) ? Cpf::hash($data['cpf']) : null;

        return Candidate::query()
            ->where(fn ($q) => $q->where('email', $data['email'])->when($cpfHash, fn ($q) => $q->orWhere('cpf_hash', $cpfHash)))
            ->orderByRaw('email = ? desc', [$data['email']])
            ->first();
    }

    private function create(array $data): Candidate
    {
        $candidate = new Candidate(Arr::only($data, self::PROFILE_FIELDS));
        $candidate->work_shifts = [];
        $candidate->setCpf($data['cpf'] ?? null);
        $candidate->source = CandidateSource::Portal;
        $candidate->status = CandidateStatus::Active;
        $candidate->save();

        $this->audit->log('candidate.created', $candidate, 'Cadastro criado pelo próprio candidato no portal Trabalhe conosco.', userId: null);

        return $candidate;
    }

    private function attach(Candidate $candidate, UploadedFile $file, DocumentType $type): void
    {
        try {
            $this->documents->handle($candidate, $file, $type, null);
        } catch (ValidationException $e) {
            // Arquivo idêntico já anexado: segue sem erro. Outros problemas voltam para o formulário.
            if (! str_contains(implode(' ', $e->errors()['file'] ?? []), 'já foi anexado')) {
                $key = $type === DocumentType::Resume ? 'resume' : 'documents';
                throw ValidationException::withMessages([$key => $e->errors()['file'] ?? ['Arquivo inválido.']]);
            }
        }
    }

    /** @return array{0: ?Application, 1: string} */
    private function apply(Candidate $candidate, JobOpening $job, string $outcome): array
    {
        if ($candidate->status !== CandidateStatus::Active) {
            return [null, 'candidate_inactive'];
        }
        $current = $job->applications()->where('candidate_id', $candidate->id)->first();
        if ($current) {
            return [$current, 'already_applied'];
        }

        $application = new Application(['candidate_id' => $candidate->id, 'source' => 'portal']);
        $application->stage = ApplicationStage::Applied;
        $application->stage_changed_at = now();
        $job->applications()->save($application);
        $application->history()->create(['from_stage' => null, 'to_stage' => ApplicationStage::Applied, 'user_id' => null, 'note' => 'Inscrição pelo portal Trabalhe conosco.']);
        $this->audit->log('application.created', $candidate, "Inscrito na vaga {$job->code} · {$job->title} pelo portal.", context: ['application' => $application->uuid, 'job' => $job->uuid], userId: null);

        return [$application, $outcome === 'created' ? 'created' : 'applied'];
    }

    private function consent(Candidate $candidate, ConsentPurpose $purpose, ?string $ip, ?string $userAgent): void
    {
        $version = (string) config('privacy.notice_version');
        $active = $candidate->consents()->active()->where('purpose', $purpose->value)->where('version', $version)->exists();
        if ($active) {
            return;
        }
        $candidate->consents()->create([
            'purpose' => $purpose, 'version' => $version, 'channel' => 'portal', 'granted_at' => now(),
            'ip_address' => $ip, 'user_agent' => Str::limit((string) $userAgent, 250, ''),
        ]);
    }

    /** @return list<string> */
    private function differences(Candidate $c, array $data): array
    {
        $diff = [];
        foreach (self::LABELS as $field => $label) {
            if ($field === 'cpf') {
                if (! empty($data['cpf']) && $c->cpf_hash && $c->cpf_hash !== Cpf::hash($data['cpf'])) {
                    $diff[] = $label;
                }

                continue;
            }
            $new = $data[$field] ?? null;
            if ($new === null || $new === '') {
                continue;
            }
            $old = $c->{$field};
            $old = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old);
            if (mb_strtolower(trim((string) $old)) !== mb_strtolower(trim((string) $new))) {
                $diff[] = $label;
            }
        }

        return $diff;
    }

    /** Protocolo legível e não sequencial (não revela quantos envios existem). */
    private function protocol(): string
    {
        do {
            // Sem 0/O, 1/I/L, que se confundem ao ditar o protocolo por telefone.
            $random = strtr(strtoupper(Str::random(6)), ['0' => '7', 'O' => 'P', 'I' => 'J', '1' => '8', 'L' => 'M']);
            $code = 'TC-'.now()->format('ymd').'-'.$random;
        } while (PortalSubmission::where('protocol', $code)->exists());

        return $code;
    }
}
