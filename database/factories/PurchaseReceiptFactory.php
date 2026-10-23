<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_number' => 'R-'.fake()->unique()->numerify('############'),
            'supplier_id' => Supplier::factory(),
            'received_on' => fake()->date(),
            'status' => 'draft',
            'notes' => null,
            'created_by' => User::factory()->admin(),
            'lock_version' => 0,
        ];
    }
}
