<?php

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'inventory_lot_id' => InventoryLot::factory(),
            'movement_type' => 'adjustment',
            'occurred_on' => fake()->date(),
            'quantity_delta' => 1,
            'unit_cost' => 100,
            'source_type' => 'factory',
            'source_id' => fake()->unique()->numberBetween(1, 2000000000),
            'notes' => null,
            'created_by' => User::factory()->admin(),
        ];
    }
}
