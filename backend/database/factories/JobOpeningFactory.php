<?php

namespace Database\Factories;

use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Recruitment\Enums\EmploymentType;
use App\Domain\Recruitment\Enums\JobReason;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Department;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobOpening> */
class JobOpeningFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->jobTitle(),
            'department_id' => Department::factory(),
            'professional_area' => ProfessionalArea::cases()[0],
            'employment_type' => EmploymentType::Clt,
            'reason' => JobReason::NewPosition,
            'positions_count' => 1,
            'description' => 'Descrição completa da vaga para os testes automatizados.',
            'is_public' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (JobOpening $job) {
            $job->code ??= 'VAGA-'.now()->format('Y').'-'.fake()->unique()->numerify('9###');
            $job->status ??= JobStatus::Draft;
        });
    }

    public function status(JobStatus $status): static
    {
        return $this->afterMaking(fn (JobOpening $job) => $job->status = $status);
    }

    public function published(): static
    {
        return $this->status(JobStatus::Published);
    }
}
