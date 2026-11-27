<?php

namespace App\Console\Commands;

use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\EvaluationCriterion;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dados fictícios para conhecer o sistema (dashboard, relatórios, quadro das vagas).
 * Nunca roda em produção e não roda se já houver candidatos, salvo com --force.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'ceof:demo-data {--force : Gera mesmo que já existam candidatos}';

    protected $description = 'Gera dados fictícios de demonstração (somente fora de produção)';

    private const FIRST = ['Ana', 'Bruno', 'Carla', 'Daniel', 'Eduarda', 'Felipe', 'Gabriela', 'Hugo', 'Isabela', 'João', 'Karina', 'Lucas', 'Mariana', 'Nathan', 'Olívia', 'Paulo', 'Raquel', 'Samuel', 'Tatiane', 'Vinícius', 'Yasmin', 'Rafael', 'Luana', 'Diego'];

    private const LAST = ['Souza', 'Lima', 'Andrade', 'Silva', 'Oliveira', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento', 'Ferreira', 'Barbosa', 'Moraes', 'Cavalcante', 'Rocha'];

    private const CITIES = [['Manaus', 'AM'], ['Manaus', 'AM'], ['Manaus', 'AM'], ['Manaus', 'AM'], ['Parintins', 'AM'], ['Itacoatiara', 'AM'], ['Manacapuru', 'AM'], ['Boa Vista', 'RR'], ['Belém', 'PA']];

    private const JOBS = [
        ['Técnico de Enfermagem', 'Enfermagem', 'nursing', 3, 'published', 9],
        ['Enfermeiro Assistencial', 'Enfermagem', 'nursing', 2, 'published', 6],
        ['Instrumentador Cirúrgico', 'Centro Cirúrgico', 'nursing', 1, 'closed', 11],
        ['Recepcionista', 'Recepção e Atendimento', 'patient_service', 2, 'published', 4],
        ['Auxiliar Administrativo', 'Administrativo', 'administrative', 1, 'closed', 7],
        ['Analista de Faturamento', 'Financeiro', 'finance', 1, 'on_hold', 3],
        ['Oftalmologista Retina', 'Corpo Clínico', 'medical', 1, 'published', 2],
        ['Ortoptista', 'Corpo Clínico', 'clinical_support', 1, 'pending_approval', 1],
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando não pode ser usado em produção.');

            return self::FAILURE;
        }
        if (Candidate::query()->exists() && ! $this->option('force')) {
            $this->warn('Já existem candidatos cadastrados. Use --force para gerar mesmo assim.');

            return self::FAILURE;
        }

        mt_srand(2026);
        $now = CarbonImmutable::now();
        $admin = User::query()->orderBy('id')->first();
        $interviewer = User::permission('interviews.evaluate')->where('is_active', true)->orderBy('id')->first() ?? $admin;

        DB::transaction(function () use ($now, $admin, $interviewer) {
            $unit = Unit::query()->firstOrCreate(['name' => 'Hospital de Olhos CEOF - Sede'], ['city' => 'Manaus', 'state' => 'AM', 'is_active' => true]);
            $departments = collect(['Enfermagem', 'Centro Cirúrgico', 'Recepção e Atendimento', 'Administrativo', 'Financeiro', 'Corpo Clínico'])
                ->mapWithKeys(fn ($n) => [$n => Department::query()->firstOrCreate(['name' => $n], ['is_active' => true])]);

            // Candidatos espalhados pelos últimos 12 meses.
            $areas = ['nursing', 'nursing', 'nursing', 'patient_service', 'patient_service', 'administrative', 'administrative', 'finance', 'medical', 'clinical_support', 'information_technology', 'general_services'];
            $sources = [CandidateSource::Internal, CandidateSource::Internal, CandidateSource::Portal, CandidateSource::Portal, CandidateSource::Portal, CandidateSource::Referral, CandidateSource::LinkedIn, CandidateSource::JobBoard, CandidateSource::WalkIn];
            $educations = [EducationLevel::HighSchool, EducationLevel::Technical, EducationLevel::Technical, EducationLevel::Higher, EducationLevel::HigherIncomplete, EducationLevel::Postgraduate];
            $candidates = collect();
            for ($i = 0; $i < 90; $i++) {
                $created = $now->subDays(mt_rand(0, 360))->setTime(mt_rand(8, 18), mt_rand(0, 59));
                [$city, $state] = self::CITIES[mt_rand(0, count(self::CITIES) - 1)];
                $name = self::FIRST[mt_rand(0, count(self::FIRST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)];
                $c = new Candidate([
                    'full_name' => $name,
                    'email' => 'demo'.($i + 1).'@exemplo.com',
                    'phone' => '92988'.str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
                    'city' => $city, 'state' => $state,
                    'professional_area' => $areas[mt_rand(0, count($areas) - 1)],
                    'education_level' => $educations[mt_rand(0, count($educations) - 1)],
                    'work_shifts' => [],
                ]);
                $c->forceFill(['source' => $sources[mt_rand(0, count($sources) - 1)], 'status' => mt_rand(1, 12) === 1 ? 'inactive' : 'active', 'created_by' => $admin?->id, 'created_at' => $created, 'updated_at' => $created]);
                $c->save();
                $candidates->push($c);
            }

            // Vagas e processos com histórico datado.
            $year = $now->year;
            $seq = (int) JobOpening::query()->where('code', 'like', "VAGA-{$year}-%")->count();
            foreach (self::JOBS as [$title, $dept, $area, $positions, $status, $monthsAgo]) {
                $opened = $now->subMonths($monthsAgo)->startOfMonth()->addDays(mt_rand(0, 10));
                $job = new JobOpening([
                    'title' => $title, 'department_id' => $departments[$dept]->id, 'unit_id' => $unit->id, 'professional_area' => $area,
                    'employment_type' => 'clt', 'reason' => 'new_position', 'positions_count' => $positions,
                    'description' => "Vaga de demonstração para {$title}.", 'is_public' => true,
                ]);
                $closed = $status === 'closed' ? $opened->addDays(mt_rand(30, 60)) : null;
                $job->forceFill([
                    'code' => sprintf('VAGA-%d-%04d', $year, ++$seq), 'status' => $status, 'requested_by' => $admin?->id, 'approved_by' => $status === 'pending_approval' ? null : $admin?->id,
                    'submitted_at' => $opened->subDays(3), 'approved_at' => $status === 'pending_approval' ? null : $opened->subDay(),
                    'published_at' => $status === 'pending_approval' ? null : $opened, 'closed_at' => $closed,
                    'status_reason' => $closed ? 'Processo encerrado.' : null, 'created_at' => $opened->subDays(4), 'updated_at' => $opened,
                ])->save();
                if ($status === 'pending_approval') {
                    continue;
                }

                $pool = $candidates->filter(fn ($c) => $c->professional_area->value === $area || mt_rand(1, 6) === 1)->shuffle()->take(mt_rand(6, 14));
                $hired = 0;
                foreach ($pool as $c) {
                    $applied = CarbonImmutable::parse(max($opened->getTimestamp(), $c->created_at->getTimestamp()))->addDays(mt_rand(0, 15));
                    $limit = $closed ?? $now;
                    if ($applied > $limit) {
                        $applied = $limit->subDays(mt_rand(1, 5));
                    }
                    $path = $this->path($hired < $positions && ($status === 'closed' || mt_rand(1, 4) === 1));
                    if (end($path) === 'hired') {
                        $hired++;
                    }
                    $this->application($job, $c, $applied, $path, $limit, $admin);
                }
            }

            // Próximas entrevistas
            $criteria = EvaluationCriterion::query()->where('is_active', true)->ordered()->get()->map->snapshot()->all();
            Application::query()->where('stage', ApplicationStage::Interview->value)->whereHas('jobOpening', fn ($q) => $q->where('status', JobStatus::Published->value))
                ->limit(5)->get()->each(function (Application $a, int $i) use ($now, $admin, $interviewer, $criteria) {
                    $iv = new Interview(['type' => 'hr', 'mode' => 'in_person', 'scheduled_at' => $now->addDays($i + 1)->setTime(9 + $i, 0), 'duration_minutes' => 60, 'location' => 'Sala de reuniões do RH']);
                    $iv->forceFill(['application_id' => $a->id, 'status' => InterviewStatus::Scheduled, 'criteria' => $criteria, 'scheduled_by' => $admin?->id])->save();
                    if ($interviewer) {
                        $iv->interviewers()->sync([$interviewer->id]);
                    }
                });
        });

        DB::statement(CandidateSearchIndex::SQL.' WHERE TRUE');
        $this->info('Dados de demonstração gerados: '.Candidate::count().' candidatos, '.JobOpening::count().' vagas, '.Application::count().' inscrições.');

        return self::SUCCESS;
    }

    /** @return list<string> Caminho de etapas da inscrição. */
    private function path(bool $hire): array
    {
        $full = ['applied', 'screening', 'shortlisted', 'interview', 'technical', 'approved', 'hired'];
        if ($hire) {
            return $full;
        }
        $stop = mt_rand(0, 5);
        $path = array_slice($full, 0, $stop + 1);
        $end = mt_rand(1, 10);
        if ($end <= 4 && $stop > 0) {
            $path[] = 'rejected';
        } elseif ($end === 5) {
            $path[] = 'withdrawn';
        }

        return $path;
    }

    /** @param list<string> $path */
    private function application(JobOpening $job, Candidate $c, CarbonImmutable $applied, array $path, CarbonImmutable $limit, ?User $admin): void
    {
        $a = new Application(['job_opening_id' => $job->id, 'candidate_id' => $c->id, 'source' => $c->source->value === 'portal' ? 'portal' : 'internal', 'created_by' => $admin?->id]);
        $a->forceFill(['stage' => 'applied', 'stage_changed_at' => $applied, 'created_at' => $applied, 'updated_at' => $applied])->save();
        DB::table('application_stage_history')->insert(['application_id' => $a->id, 'from_stage' => null, 'to_stage' => 'applied', 'user_id' => $admin?->id, 'created_at' => $applied]);

        $at = $applied;
        $prev = 'applied';
        foreach (array_slice($path, 1) as $stage) {
            $next = $at->addDays(mt_rand(2, 9));
            if ($next > $limit) {
                break;
            }
            $at = $next;
            DB::table('application_stage_history')->insert([
                'application_id' => $a->id, 'from_stage' => $prev, 'to_stage' => $stage, 'user_id' => $admin?->id, 'created_at' => $at,
                'reason' => $stage === 'rejected' ? 'profile_mismatch' : null,
            ]);
            $a->forceFill(['previous_stage' => $prev, 'stage' => $stage, 'stage_changed_at' => $at, 'hired_at' => $stage === 'hired' ? $at : null, 'rejection_reason' => $stage === 'rejected' ? 'profile_mismatch' : null, 'updated_at' => $at])->save();
            $prev = $stage;
        }
    }
}
