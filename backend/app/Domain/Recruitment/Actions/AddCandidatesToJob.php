<?php

namespace App\Domain\Recruitment\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Inscreve candidatos do banco de talentos numa vaga (etapa Inscrição). */
class AddCandidatesToJob
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $candidateUuids
     * @return array{added: list<Application>, already: list<string>, inactive: list<string>}
     */
    public function handle(JobOpening $job, array $candidateUuids, User $actor, string $source = 'internal'): array
    {
        return DB::transaction(function () use ($job, $candidateUuids, $actor, $source) {
            $candidates = Candidate::query()->whereIn('uuid', $candidateUuids)->get();
            $existing = $job->applications()->whereIn('candidate_id', $candidates->modelKeys())->pluck('candidate_id')->all();
            $result = ['added' => [], 'already' => [], 'inactive' => []];

            foreach ($candidates as $candidate) {
                if (in_array($candidate->id, $existing, true)) {
                    $result['already'][] = $candidate->full_name;

                    continue;
                }
                if ($candidate->status !== CandidateStatus::Active) {
                    $result['inactive'][] = $candidate->full_name;

                    continue;
                }

                $application = new Application(['candidate_id' => $candidate->id, 'source' => $source, 'created_by' => $actor->id]);
                $application->stage = ApplicationStage::Applied;
                $application->stage_changed_at = now();
                $job->applications()->save($application);
                $application->history()->create(['from_stage' => null, 'to_stage' => ApplicationStage::Applied, 'user_id' => $actor->id]);

                $this->audit->log('application.created', $candidate, "Inscrito na vaga {$job->code} · {$job->title}.", context: ['application' => $application->uuid, 'job' => $job->uuid]);
                $result['added'][] = $application;
            }

            return $result;
        });
    }
}
