<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'operation_number' => 'SO-'.fake()->unique()->numerify('############'),
            'operation_type' => 'outbound',
            'occurred_on' => fake()->date(),
            'notes' => null,
            'created_by' => User::factory()->admin(),
        ];
    }
}
