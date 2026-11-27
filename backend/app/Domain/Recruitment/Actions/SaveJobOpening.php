<?php

namespace App\Domain\Recruitment\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cria (como rascunho) ou edita uma vaga e seus requisitos. */
class SaveJobOpening
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function handle(JobOpening $job, array $data, User $actor): JobOpening
    {
        return DB::transaction(function () use ($job, $data, $actor) {
            $creating = ! $job->exists;

            if (! $creating && isset($data['positions_count']) && (int) $data['positions_count'] < $job->hiredCount()) {
                throw ValidationException::withMessages(['positions_count' => 'Já há '.$job->hiredCount().' contratação(ões) nesta vaga; a quantidade não pode ser menor.']);
            }

            $original = $job->getAttributes();
            $requirementsBefore = $creating ? null : $job->requirements()->get(['description', 'is_mandatory'])->toJson();

            $job->fill(Arr::only($data, $job->getFillable()));

            if ($creating) {
                $job->code = $this->nextCode();
                $job->status = JobStatus::Draft;
                $job->requested_by = $actor->id;
            }

            $job->save();

            $job->requirements()->delete();
            foreach (array_values($data['requirements'] ?? []) as $i => $row) {
                $job->requirements()->create(['description' => trim($row['description']), 'is_mandatory' => (bool) ($row['is_mandatory'] ?? true), 'sort' => $i]);
            }

            if ($creating) {
                $job->statusHistory()->create(['from_status' => null, 'to_status' => JobStatus::Draft, 'user_id' => $actor->id]);
                $this->audit->log('job.created', $job, "Vaga {$job->code} criada.", new: Arr::only($job->getAttributes(), ['title', 'department_id', 'positions_count']));
            } else {
                $changed = $this->audit->logChanges('job.updated', $job, $original, 'Dados da vaga alterados.');
                if (! $changed && $requirementsBefore !== $job->requirements()->get(['description', 'is_mandatory'])->toJson()) {
                    $this->audit->log('job.updated', $job, 'Requisitos da vaga alterados.');
                }
            }

            return $job->refresh();
        });
    }

    /** Código sequencial por ano: VAGA-2026-0001. */
    private function nextCode(): string
    {
        $year = now()->format('Y');
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["job-code-$year"]);
        $last = JobOpening::query()->where('code', 'like', "VAGA-$year-%")->max('code');
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('VAGA-%s-%04d', $year, $next);
    }
}
