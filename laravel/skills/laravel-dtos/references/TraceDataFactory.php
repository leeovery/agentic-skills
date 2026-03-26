<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Enums\TraceType;
use Illuminate\Support\Str;

/**
 * Test factory for TraceData.
 *
 * Advanced factory example with state methods for different scenarios.
 */
class TraceDataFactory extends DataTestFactory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'provider' => fake()->word(),
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
            'type' => TraceType::Pension,
            'fromDate' => fake()->dateTimeBetween('-10 years', '-5 years')->format('d-m-Y'),
            'toDate' => fake()->dateTimeBetween('-4 years')->format('d-m-Y'),
            'documents' => collect(),
        ];
    }

    /**
     * Create a pension trace via provider.
     */
    public function pensionViaProvider(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Pension,
            'provider' => fake()->company,
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
        ]);
    }

    /**
     * Create a pension trace via employment.
     */
    public function pensionViaEmployment(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Pension,
            'provider' => null,
            'policyNumber' => null,
            'employer' => fake()->company,
            'industry' => fake()->word,
        ]);
    }

    /**
     * Create an investment trace.
     */
    public function investment(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Investment,
            'provider' => fake()->company,
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
        ]);
    }
}
