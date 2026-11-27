<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Privacy\Actions\AnonymizeCandidate;
use App\Domain\Privacy\RetentionPolicy;
use App\Models\Candidate;
use Illuminate\Console\Command;

/** Aplica a política de retenção da LGPD (agendado todo dia). */
class ApplyRetentionPolicy extends Command
{
    protected $signature = 'privacy:apply-retention {--dry-run : Só lista o que venceu, sem alterar nada}';

    protected $description = 'Anonimiza cadastros com prazo de retenção vencido e remove auditoria antiga';

    public function handle(RetentionPolicy $policy, AnonymizeCandidate $anonymize, AuditLogger $audit): int
    {
        $dry = $this->option('dry-run') || ! config('privacy.retention_enabled');
        $due = $policy->due()->orderBy('candidates.id')->get(['candidates.*']);
        $purge = $policy->purgeableAuditLogs()->count();

        $this->info(($dry ? '[simulação] ' : '')."Cadastros com prazo vencido: {$due->count()}. Registros de auditoria antigos: {$purge}.");
        if ($dry) {
            foreach ($due as $c) {
                $this->line(" - #{$c->id} ".($c->trashed() ? '(excluído) ' : '').'cadastrado em '.$c->created_at->format('d/m/Y'));
            }

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($due as $candidate) {
            /** @var Candidate $candidate */
            $reason = $candidate->trashed() ? 'Cadastro excluído há mais de '.config('privacy.deleted_grace_days').' dias.' : 'Prazo de retenção vencido.';
            try {
                $anonymize->handle($candidate, $reason, null);
                $done++;
            } catch (\Throwable $e) {
                $this->error("#{$candidate->id}: ".$e->getMessage());
            }
        }

        if ($purge > 0) {
            $policy->purgeableAuditLogs()->delete();
        }
        if ($done > 0 || $purge > 0) {
            $audit->log('privacy.retention_applied', null, "Retenção aplicada: {$done} cadastro(s) anonimizado(s), {$purge} registro(s) de auditoria antigos removidos.", context: ['anonymized' => $done, 'audit_purged' => $purge], userId: null);
        }
        $this->info("Anonimizados: {$done}.");

        return self::SUCCESS;
    }
}
