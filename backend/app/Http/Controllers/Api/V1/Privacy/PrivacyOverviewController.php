<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Portal\Enums\ConsentPurpose;
use App\Domain\Privacy\RetentionPolicy;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateConsent;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Painel LGPD: pendências, retenção, consentimentos e registro das operações de tratamento. */
class PrivacyOverviewController extends Controller
{
    public function __invoke(Request $request, RetentionPolicy $policy): JsonResponse
    {
        abort_unless($request->user()->can(Permission::PrivacyManage->value), 403);
        $open = PrivacyRequest::whereIn('status', ['open', 'in_progress']);

        $upcoming = $policy->upcoming(30)->selectRaw('candidates.id, candidates.uuid, candidates.full_name, candidates.created_at, '.RetentionPolicy::expiresAtSql().' as expires_at')
            ->orderByRaw('expires_at')->limit(50)->get();

        return response()->json(['data' => [
            'requests' => [
                'open' => (clone $open)->count(),
                'overdue' => (clone $open)->where('due_on', '<', now()->toDateString())->count(),
                'completed_last_30_days' => PrivacyRequest::where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
            ],
            'retention' => [
                'due_now' => $policy->due()->count(),
                'upcoming_count' => $policy->upcoming(30)->count(),
                'upcoming' => $upcoming->map(fn (Candidate $c) => ['id' => $c->uuid, 'name' => $c->full_name, 'expires_at' => substr((string) $c->getAttribute('expires_at'), 0, 10)]),
                'anonymized_total' => Candidate::withTrashed()->whereNotNull('anonymized_at')->count(),
                'audit_purgeable' => $policy->purgeableAuditLogs()->count(),
                'settings' => [
                    'talent_pool_months' => (int) config('privacy.talent_pool_retention_months'),
                    'no_consent_months' => (int) config('privacy.no_consent_retention_months'),
                    'deleted_grace_days' => (int) config('privacy.deleted_grace_days'),
                    'keep_hired' => (bool) config('privacy.keep_hired'),
                    'audit_months' => (int) config('privacy.audit_retention_months'),
                    'enabled' => (bool) config('privacy.retention_enabled'),
                ],
            ],
            'consents' => [
                'talent_pool_active' => CandidateConsent::active()->where('purpose', ConsentPurpose::TalentPool->value)->distinct()->count('candidate_id'),
                'talent_pool_revoked' => CandidateConsent::whereNotNull('revoked_at')->where('purpose', ConsentPurpose::TalentPool->value)->count(),
                'privacy_notice' => CandidateConsent::where('purpose', ConsentPurpose::PrivacyNotice->value)->distinct()->count('candidate_id'),
                'notice_version' => config('privacy.notice_version'),
            ],
            'processing_activities' => config('privacy.processing_activities'),
            'dpo' => ['name' => config('privacy.dpo_name'), 'email' => config('privacy.dpo_email'), 'controller' => config('privacy.controller')],
        ]]);
    }
}
