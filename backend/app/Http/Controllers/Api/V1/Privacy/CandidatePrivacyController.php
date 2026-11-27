<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Domain\Audit\AuditLogger;
use App\Domain\Portal\Enums\ConsentPurpose;
use App\Domain\Privacy\Actions\AnonymizeCandidate;
use App\Domain\Privacy\CandidateDataExport;
use App\Domain\Privacy\RetentionPolicy;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateConsent;
use App\Models\PortalSubmission;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Aba "Privacidade" do candidato: consentimentos, envios do portal, retenção, exportação e anonimização. */
class CandidatePrivacyController extends Controller
{
    public function show(Candidate $candidate, RetentionPolicy $policy): JsonResponse
    {
        $this->authorize('managePrivacy', $candidate);
        $candidate->load('consents.recorder:id,name', 'portalSubmissions.jobOpening:id,code,title');

        return response()->json(['data' => [
            'anonymized_at' => $candidate->anonymized_at?->toIso8601String(),
            'retention' => [
                'expires_at' => $policy->expiresAt($candidate)?->toDateString(),
                'has_open_process' => $policy->hasOpenProcess($candidate),
                'talent_pool_months' => (int) config('privacy.talent_pool_retention_months'),
                'no_consent_months' => (int) config('privacy.no_consent_retention_months'),
            ],
            'consents' => $candidate->consents->map(fn (CandidateConsent $c) => [
                'id' => $c->uuid,
                'purpose' => $c->purpose->value,
                'purpose_label' => $c->purpose->label(),
                'version' => $c->version,
                'channel' => $c->channel,
                'channel_label' => $c->channel === 'portal' ? 'Portal Trabalhe conosco' : 'Registrado pelo RH',
                'granted_at' => $c->granted_at->toIso8601String(),
                'revoked_at' => $c->revoked_at?->toIso8601String(),
                'revoked_reason' => $c->revoked_reason,
                'recorded_by' => $c->recorder?->name,
                'ip_address' => $c->ip_address,
            ]),
            'submissions' => $candidate->portalSubmissions->map(fn (PortalSubmission $s) => [
                'protocol' => $s->protocol,
                'created_at' => $s->created_at->toIso8601String(),
                'job' => $s->jobOpening ? $s->jobOpening->code.' · '.$s->jobOpening->title : null,
                'is_new_candidate' => $s->is_new_candidate,
                'payload' => collect($s->payload)->except(['job', 'talent_pool_consent'])->all(),
            ]),
            'requests' => PrivacyRequest::where('candidate_id', $candidate->id)->latest('id')->get()
                ->map(fn (PrivacyRequest $r) => ['id' => $r->uuid, 'protocol' => $r->protocol, 'type_label' => $r->type->label(), 'status_label' => $r->status->label(), 'status' => $r->status->value, 'due_on' => $r->due_on->toDateString()]),
            'purposes' => ConsentPurpose::options(),
            'notice_version' => config('privacy.notice_version'),
        ]]);
    }

    /** Registra um consentimento obtido fora do portal (por escrito, e-mail etc.). */
    public function storeConsent(Request $request, Candidate $candidate, AuditLogger $audit): JsonResponse
    {
        $this->authorize('managePrivacy', $candidate);
        abort_if($candidate->isAnonymized(), 422, 'Cadastro anonimizado.');
        $data = $request->validate([
            'purpose' => ['required', Rule::enum(ConsentPurpose::class)],
            'evidence' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['purpose' => 'finalidade', 'evidence' => 'como foi obtido']);

        $purpose = ConsentPurpose::from($data['purpose']);
        $candidate->consents()->active()->where('purpose', $purpose->value)->update(['revoked_at' => now(), 'revoked_reason' => 'Substituído por novo registro.']);
        $candidate->consents()->create([
            'purpose' => $purpose, 'version' => (string) config('privacy.notice_version'), 'channel' => 'internal',
            'granted_at' => now(), 'recorded_by' => $request->user()->id, 'user_agent' => Str::limit($data['evidence'], 250, ''),
        ]);
        $audit->log('candidate.consent_granted', $candidate, "{$purpose->label()} registrado pelo RH ({$data['evidence']}).");

        return response()->json(['message' => 'Consentimento registrado.'], 201);
    }

    public function revokeConsent(Request $request, Candidate $candidate, string $consent, AuditLogger $audit): JsonResponse
    {
        $this->authorize('managePrivacy', $candidate);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);
        $model = $candidate->consents()->where('uuid', $consent)->firstOrFail();
        abort_if($model->revoked_at !== null, 422, 'Este consentimento já foi revogado.');

        $model->update(['revoked_at' => now(), 'revoked_reason' => $data['reason']]);
        $audit->log('candidate.consent_revoked', $candidate, "{$model->purpose->label()} revogado. Motivo: {$data['reason']}");

        return response()->json(['message' => 'Consentimento revogado.']);
    }

    /** Relatório dos dados do titular em JSON (acesso e portabilidade). */
    public function export(Candidate $candidate, CandidateDataExport $export, AuditLogger $audit): Response
    {
        $this->authorize('managePrivacy', $candidate);
        abort_if($candidate->isAnonymized(), 422, 'Cadastro anonimizado: não há dados pessoais para exportar.');

        $json = json_encode($export->build($candidate), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $audit->log('candidate.data_exported', $candidate, 'Relatório de dados pessoais gerado (acesso/portabilidade).');

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="dados-titular-'.Str::substr($candidate->uuid, 0, 8).'-'.now()->format('Ymd').'.json"',
        ]);
    }

    public function anonymize(Request $request, Candidate $candidate, AnonymizeCandidate $anonymize): JsonResponse
    {
        $this->authorize('managePrivacy', $candidate);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'request' => ['nullable', 'uuid'],
            'confirm' => ['required', 'in:ANONIMIZAR'],
        ], ['confirm.in' => 'Digite ANONIMIZAR para confirmar.'], ['reason' => 'motivo', 'confirm' => 'confirmação']);

        $privacyRequest = isset($data['request']) ? PrivacyRequest::where('uuid', $data['request'])->first() : null;
        $anonymize->handle($candidate, $data['reason'], $request->user(), $privacyRequest);

        return response()->json(['message' => 'Cadastro anonimizado.']);
    }
}
