<?php

declare(strict_types=1);

namespace Database\Factories\Data;

/**
 * Test factory for AddressData.
 *
 * Simple factory example with basic fake data.
 */
class AddressDataFactory extends DataTestFactory
{
    public function definition(): array
    {
        return [
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'address3' => null,
            'town' => fake()->city(),
            'county' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country' => 'UK',
            'fromDate' => fake()->dateTimeBetween('-10 years', '-5 years')->format('d-m-Y'),
            'toDate' => fake()->dateTimeBetween('-4 years')->format('d-m-Y'),
            'current' => true,
        ];
    }
}
