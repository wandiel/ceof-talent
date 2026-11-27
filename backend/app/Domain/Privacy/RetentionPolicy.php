<?php

namespace App\Domain\Privacy;

use App\Domain\Portal\Enums\ConsentPurpose;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Candidate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Política de retenção (LGPD, art. 15 e 16).
 *
 * - Última atividade: a data mais recente entre a atualização do cadastro, uma inscrição,
 *   um envio pelo portal ou um consentimento.
 * - Prazo padrão (com consentimento do banco de talentos ou cadastro feito pelo RH):
 *   `privacy.talent_pool_retention_months` após a última atividade.
 * - Quem recusou ou revogou o banco de talentos: `privacy.no_consent_retention_months`.
 * - Cadastros excluídos: anonimizados após `privacy.deleted_grace_days`.
 * - Nunca entram: quem tem processo seletivo em andamento e, se `privacy.keep_hired`, quem foi
 *   contratado (os dados seguem para o registro de empregado).
 */
class RetentionPolicy
{
    /** Expressão SQL com a data da última atividade do candidato. */
    public static function lastActivitySql(): string
    {
        return 'GREATEST(candidates.updated_at,
            COALESCE((SELECT max(a.updated_at) FROM applications a WHERE a.candidate_id = candidates.id), candidates.created_at),
            COALESCE((SELECT max(p.created_at) FROM portal_submissions p WHERE p.candidate_id = candidates.id), candidates.created_at),
            COALESCE((SELECT max(k.granted_at) FROM candidate_consents k WHERE k.candidate_id = candidates.id), candidates.created_at))';
    }

    /** SQL: o candidato recusou ou revogou o banco de talentos (aceitou o aviso, sem consentimento ativo). */
    public static function declinedPoolSql(): string
    {
        $pool = ConsentPurpose::TalentPool->value;
        $notice = ConsentPurpose::PrivacyNotice->value;

        return "(NOT EXISTS (SELECT 1 FROM candidate_consents k WHERE k.candidate_id = candidates.id AND k.purpose = '{$pool}' AND k.revoked_at IS NULL)
            AND (EXISTS (SELECT 1 FROM candidate_consents k WHERE k.candidate_id = candidates.id AND k.purpose = '{$pool}')
              OR EXISTS (SELECT 1 FROM candidate_consents k WHERE k.candidate_id = candidates.id AND k.purpose = '{$notice}')))";
    }

    /** Data limite (sem contar exclusões) em que o candidato deve ser anonimizado. */
    public static function expiresAtSql(): string
    {
        $long = (int) config('privacy.talent_pool_retention_months');
        $short = (int) config('privacy.no_consent_retention_months');

        return '('.self::lastActivitySql().' + (CASE WHEN '.self::declinedPoolSql()." THEN {$short} ELSE {$long} END) * interval '1 month')";
    }

    /** @return Builder<Candidate> Candidatos que podem entrar na regra (não anonimizados e sem processo em andamento). */
    private function eligible(): Builder
    {
        $closed = [ApplicationStage::Hired->value, ApplicationStage::Rejected->value, ApplicationStage::Withdrawn->value];

        return Candidate::withTrashed()->whereNull('candidates.anonymized_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('applications as oa')->whereColumn('oa.candidate_id', 'candidates.id')->whereNotIn('oa.stage', $closed))
            ->when(config('privacy.keep_hired'), fn (Builder $q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('applications as ha')->whereColumn('ha.candidate_id', 'candidates.id')->where('ha.stage', ApplicationStage::Hired->value)));
    }

    /** @return Builder<Candidate> Candidatos com prazo vencido em `$at`. */
    public function due(?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now();
        $grace = (int) config('privacy.deleted_grace_days');

        return $this->eligible()->where(fn (Builder $q) => $q
            ->whereRaw(self::expiresAtSql().' <= ?', [$at->toDateTimeString()])
            ->orWhere(fn (Builder $d) => $d->whereNotNull('candidates.deleted_at')->where('candidates.deleted_at', '<=', $at->subDays($grace)->toDateTimeString())));
    }

    /** @return Builder<Candidate> Candidatos cujo prazo vence nos próximos `$days` dias (para renovar ou revisar). */
    public function upcoming(int $days = 30): Builder
    {
        $now = CarbonImmutable::now();

        return $this->eligible()->whereNull('candidates.deleted_at')
            ->whereRaw(self::expiresAtSql().' > ?', [$now->toDateTimeString()])
            ->whereRaw(self::expiresAtSql().' <= ?', [$now->addDays($days)->toDateTimeString()]);
    }

    /** Data em que o cadastro vence pela regra (null se não se aplica). */
    public function expiresAt(Candidate $candidate): ?CarbonImmutable
    {
        if ($candidate->isAnonymized()) {
            return null;
        }
        $value = Candidate::withTrashed()->whereKey($candidate->id)->selectRaw(self::expiresAtSql().' as e')->value('e');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    public function hasOpenProcess(Candidate $candidate): bool
    {
        return ! $this->eligible()->whereKey($candidate->id)->exists() && ! $candidate->isAnonymized();
    }

    /** Registros de auditoria mais antigos que o prazo (removidos pela rotina). */
    public function purgeableAuditLogs(): \Illuminate\Database\Query\Builder
    {
        return DB::table('audit_logs')->where('created_at', '<', now()->subMonths((int) config('privacy.audit_retention_months')));
    }
}
