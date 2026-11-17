<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => 'ON'.now('Asia/Tokyo')->format('ym').fake()->unique()->regexify('[A-HJ-NP-Z2-9]{4}'),
            'customer_id' => User::factory(),
            'order_source' => 'admin',
            'ordered_on' => fake()->date(),
            'status' => 'draft',
            'subtotal' => 0,
            'total' => 0,
            'notes' => null,
            'created_by' => User::factory()->admin(),
            'lock_version' => 0,
        ];
    }
}
