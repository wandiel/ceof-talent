<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Domain\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Consulta da trilha de auditoria (somente leitura). */
class AuditLogController extends Controller
{
    /** Grupos de eventos para o filtro da tela. */
    private const GROUPS = [
        'auth' => 'Acesso ao sistema',
        'user' => 'Usuários e perfis',
        'candidate' => 'Candidatos',
        'application' => 'Processos seletivos',
        'job' => 'Vagas',
        'interview' => 'Entrevistas',
        'report' => 'Relatórios exportados',
        'privacy' => 'LGPD',
    ];

    private const SUBJECTS = [
        'App\\Models\\Candidate' => 'Candidato',
        'App\\Models\\User' => 'Usuário',
        'App\\Models\\JobOpening' => 'Vaga',
        'App\\Models\\Interview' => 'Entrevista',
        'App\\Models\\PrivacyRequest' => 'Solicitação LGPD',
        'App\\Models\\Tag' => 'Etiqueta',
        'App\\Models\\EvaluationCriterion' => 'Critério',
        'App\\Models\\Department' => 'Departamento',
        'App\\Models\\Unit' => 'Unidade',
        'App\\Models\\Position' => 'Cargo',
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::AuditView->value), 403);
        $f = $request->validate([
            'group' => ['nullable', 'in:'.implode(',', array_keys(self::GROUPS))],
            'event' => ['nullable', 'string', 'max:80'],
            'user' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'ip' => ['nullable', 'ip'],
        ]);
        $tz = config('app.timezone');

        $page = AuditLog::query()->with('user:id,uuid,name')
            ->when($f['group'] ?? null, fn (Builder $q, $g) => $q->where('event', 'like', $g.'.%'))
            ->when($f['event'] ?? null, fn (Builder $q, $e) => $q->where('event', $e))
            ->when($f['user'] ?? null, fn (Builder $q, $u) => $q->where('user_id', User::where('uuid', $u)->value('id') ?? 0))
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $tz)->startOfDay()))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $tz)->endOfDay()))
            ->when($f['search'] ?? null, fn (Builder $q, $s) => $q->where('description', 'ILIKE', '%'.addcslashes($s, '%_\\').'%'))
            ->when($f['ip'] ?? null, fn (Builder $q, $ip) => $q->where('ip_address', $ip))
            ->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'event' => $l->event,
                'description' => $l->description,
                'user' => $l->user ? ['id' => $l->user->uuid, 'name' => $l->user->name] : null,
                'subject' => $l->auditable_type ? ['type' => self::SUBJECTS[$l->auditable_type] ?? class_basename($l->auditable_type), 'id' => $l->auditable_id] : null,
                'old_values' => $l->old_values,
                'new_values' => $l->new_values,
                'context' => $l->context,
                'ip_address' => $l->ip_address,
                'user_agent' => $l->user_agent,
                'created_at' => $l->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'groups' => collect(self::GROUPS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'users' => User::query()->orderBy('name')->get(['uuid', 'name'])->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name]),
            ],
        ]);
    }
}
