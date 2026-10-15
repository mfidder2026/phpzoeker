<?php

namespace Database\Factories;

use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        $name = fake()->firstName().' '.fake()->lastName();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'job_title' => 'Senior Developer',
            'cv_titles' => ['Senior PHP / Laravel Developer'],
            'seniority' => Seniority::Senior,
            'email' => fake()->safeEmail(),
            'phone' => null,
            'city' => 'Rotterdam',
            'birth_date' => '1990-01-01',
            'driving_license' => true,
            'own_transport' => null,
            'available_from' => null,
            'hours_per_week' => 40,
            'hourly_rate' => 95,
            'min_hourly_rate' => 95,
            'rate_note' => 'excl. btw',
            'languages' => ['nl' => 'moedertaal', 'en' => 'goed'],
            'status' => CandidateStatus::Beschikbaar,
            'cc_self_on_offers' => false,
        ];
    }
}
