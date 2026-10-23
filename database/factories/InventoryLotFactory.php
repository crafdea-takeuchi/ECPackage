<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryLotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'supplier_id' => Supplier::factory(),
            'purchase_receipt_line_id' => PurchaseReceiptLine::factory(),
            'lot_number' => fake()->unique()->bothify('LOT-########'),
            'received_on' => fake()->date(),
            'manufactured_on' => null,
            'expires_on' => null,
            'initial_quantity' => 10,
            'current_quantity' => 10,
            'unit_purchase_price' => 100,
            'status' => 'active',
        ];
    }
}
