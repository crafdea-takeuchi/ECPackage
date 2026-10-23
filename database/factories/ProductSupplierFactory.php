<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSupplier>
 */
class ProductSupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'product_id' => Product::factory(),
            'supplier_product_code' => fake()->bothify('ITEM-####'),
            'default_purchase_price' => fake()->randomFloat(4, 1, 100000),
            'minimum_order_quantity' => 1,
            'lead_time_days' => fake()->numberBetween(0, 30),
            'is_primary' => false,
            'status' => 'active',
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true, 'status' => 'active']);
    }
}
