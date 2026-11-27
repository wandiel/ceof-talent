<?php

namespace Database\Factories;

use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Candidate> */
class CandidateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '9298'.fake()->unique()->numerify('#######'),
            'city' => 'Manaus',
            'state' => 'AM',
            'professional_area' => fake()->randomElement(ProfessionalArea::cases()),
            'education_level' => fake()->randomElement(EducationLevel::cases()),
            'desired_position' => fake()->jobTitle(),
            'work_shifts' => [],
            'source' => CandidateSource::Internal,
            'status' => CandidateStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CandidateStatus::Inactive]);
    }

    public function withCpf(string $cpf): static
    {
        return $this->afterMaking(fn (Candidate $c) => $c->setCpf($cpf));
    }
}
