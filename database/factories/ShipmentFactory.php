<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipment_number' => 'S-'.fake()->unique()->numerify('############'),
            'order_id' => Order::factory(),
            'status' => 'draft',
            'carrier' => null,
            'tracking_number' => null,
            'scheduled_on' => null,
            'shipped_on' => null,
            'notes' => null,
            'created_by' => User::factory()->admin(),
            'lock_version' => 0,
        ];
    }
}
