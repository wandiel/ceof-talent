<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Privacy\Enums\PrivacyRequestStatus;
use App\Domain\Privacy\Enums\PrivacyRequestType;
use App\Domain\Privacy\Enums\RequestChannel;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\PrivacyRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Solicitações de titulares (LGPD, art. 18): registro, prazo e atendimento. */
class PrivacyRequestController extends Controller
{
    /** Prazo de resposta (art. 19, II: até 15 dias para a declaração completa). */
    public const DEADLINE_DAYS = 15;

    private function authorizePrivacy(Request $request): void
    {
        abort_unless($request->user()->can(Permission::PrivacyManage->value), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePrivacy($request);
        $f = $request->validate([
            'status' => ['nullable', 'array'], 'status.*' => [Rule::enum(PrivacyRequestStatus::class)],
            'type' => ['nullable', Rule::enum(PrivacyRequestType::class)],
            'overdue' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'candidate' => ['nullable', 'uuid'],
        ]);

        $page = PrivacyRequest::query()->with('candidate:id,uuid,full_name,anonymized_at', 'handler:id,name')
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->whereIn('status', $s))
            ->when($f['type'] ?? null, fn (Builder $q, $t) => $q->where('type', $t))
            ->when(filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->whereIn('status', ['open', 'in_progress'])->where('due_on', '<', now()->toDateString()))
            ->when($f['candidate'] ?? null, fn (Builder $q, $c) => $q->whereHas('candidate', fn ($w) => $w->where('uuid', $c)))
            ->when($f['search'] ?? null, fn (Builder $q, $s) => $q->where(fn ($w) => $w->where('requester_name', 'ILIKE', '%'.addcslashes($s, '%_\\').'%')->orWhere('protocol', 'ILIKE', '%'.addcslashes($s, '%_\\').'%')->orWhere('requester_email', 'ILIKE', '%'.addcslashes($s, '%_\\').'%')))
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")->orderBy('due_on')->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PrivacyRequest $r) => $this->present($r)),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorizePrivacy($request);

        return response()->json(['data' => [
            'types' => PrivacyRequestType::options(),
            'statuses' => PrivacyRequestStatus::options(),
            'channels' => RequestChannel::options(),
            'deadline_days' => self::DEADLINE_DAYS,
        ]]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizePrivacy($request);
        $data = $this->validated($request, creating: true);

        $r = new PrivacyRequest(Arr::except($data, ['candidate']));
        $r->protocol = $this->protocol();
        $r->status = PrivacyRequestStatus::Open;
        $r->due_on = now()->addDays(self::DEADLINE_DAYS)->toDateString();
        $r->candidate_id = $this->candidateId($data['candidate'] ?? null);
        $r->created_by = $request->user()->id;
        $r->save();

        $audit->log('privacy.request_created', $r, "Solicitação {$r->protocol} registrada: {$r->type->label()}.");
        if ($r->candidate_id) {
            $audit->log('privacy.request_created', $r->candidate, "Solicitação de titular {$r->protocol}: {$r->type->label()}.");
        }

        return response()->json(['data' => $this->present($r->fresh(['candidate', 'handler']))], 201);
    }

    public function show(Request $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $this->authorizePrivacy($request);
        $privacyRequest->load('candidate', 'handler:id,name', 'creator:id,name');

        return response()->json(['data' => [
            ...$this->present($privacyRequest),
            'description' => $privacyRequest->description,
            'response' => $privacyRequest->response,
            'requester_phone' => $privacyRequest->requester_phone,
            'created_by' => $privacyRequest->creator?->name,
            'history' => $privacyRequest->auditLogs()->with('user:id,name')->latest('id')->get()
                ->map(fn (AuditLog $l) => ['description' => $l->description, 'user' => $l->user?->name ?? 'Sistema', 'created_at' => $l->created_at->toIso8601String()]),
        ]]);
    }

    public function update(Request $request, PrivacyRequest $privacyRequest, AuditLogger $audit): JsonResponse
    {
        $this->authorizePrivacy($request);
        $data = $this->validated($request, creating: false);
        $before = $privacyRequest->status;

        $privacyRequest->fill(Arr::except($data, ['candidate']));
        if (array_key_exists('candidate', $data)) {
            $privacyRequest->candidate_id = $this->candidateId($data['candidate']);
        }
        $privacyRequest->handled_by = $request->user()->id;
        $status = $privacyRequest->status;
        if ($status->isClosed() && ! $before->isClosed()) {
            $privacyRequest->completed_at = now();
        } elseif (! $status->isClosed()) {
            $privacyRequest->completed_at = null;
        }
        $privacyRequest->save();

        $text = $before !== $status ? "Situação: {$before->label()} → {$status->label()}." : 'Solicitação atualizada.';
        $audit->log('privacy.request_updated', $privacyRequest, $text);

        return response()->json(['data' => $this->present($privacyRequest->fresh(['candidate', 'handler']))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'requester_name' => [$req, 'string', 'min:3', 'max:150'],
            'requester_email' => ['nullable', 'email:rfc', 'max:191'],
            'requester_phone' => ['nullable', 'string', 'max:20'],
            'type' => [$req, Rule::enum(PrivacyRequestType::class)],
            'channel' => [$req, Rule::enum(RequestChannel::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'candidate' => ['nullable', 'uuid'],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::enum(PrivacyRequestStatus::class)],
            'response' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => in_array($request->input('status'), ['completed', 'rejected'], true))],
        ], ['response.required' => 'Descreva a resposta dada ao titular para concluir ou indeferir.'], [
            'requester_name' => 'nome do titular', 'requester_email' => 'e-mail', 'type' => 'tipo de pedido', 'channel' => 'canal', 'response' => 'resposta',
        ]);
        if (isset($data['requester_phone'])) {
            $data['requester_phone'] = preg_replace('/\D/', '', $data['requester_phone']);
        }

        return $data;
    }

    private function candidateId(?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        return Candidate::withTrashed()->where('uuid', $uuid)->value('id') ?? abort(422, 'Candidato não encontrado.');
    }

    private function protocol(): string
    {
        do {
            $code = 'LGPD-'.now()->format('ymd').'-'.strtr(strtoupper(Str::random(5)), ['0' => '7', 'O' => 'P', 'I' => 'J', '1' => '8', 'L' => 'M']);
        } while (PrivacyRequest::where('protocol', $code)->exists());

        return $code;
    }

    /** @return array<string, mixed> */
    private function present(PrivacyRequest $r): array
    {
        return [
            'id' => $r->uuid,
            'protocol' => $r->protocol,
            'requester_name' => $r->requester_name,
            'requester_email' => $r->requester_email,
            'type' => $r->type->value,
            'type_label' => $r->type->label(),
            'channel' => $r->channel->value,
            'channel_label' => $r->channel->label(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'due_on' => $r->due_on->toDateString(),
            'overdue' => $r->isOverdue(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            'handler' => $r->handler?->name,
            'candidate' => $r->candidate ? ['id' => $r->candidate->uuid, 'name' => $r->candidate->full_name, 'anonymized' => $r->candidate->isAnonymized()] : null,
        ];
    }
}
