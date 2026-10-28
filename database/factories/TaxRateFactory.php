<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TaxRateFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->lexify('tax-????????'), 'name' => '標準税率', 'rate' => '10.00', 'status' => 'active'];
    }
}
