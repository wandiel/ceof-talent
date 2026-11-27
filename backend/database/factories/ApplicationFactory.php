<?php

namespace Database\Factories;

use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_opening_id' => JobOpening::factory()->published(),
            'candidate_id' => Candidate::factory(),
            'source' => 'internal',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Application $a) {
            $a->stage ??= ApplicationStage::Applied;
            $a->stage_changed_at ??= now();
        });
    }

    public function stage(ApplicationStage $stage): static
    {
        return $this->afterMaking(fn (Application $a) => $a->stage = $stage);
    }
}
