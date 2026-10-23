<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReceiptLineFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 100);
        $price = fake()->numberBetween(1, 10000);

        return [
            'purchase_receipt_id' => PurchaseReceipt::factory(),
            'line_number' => 1,
            'product_id' => Product::factory(),
            'product_supplier_id' => ProductSupplier::factory(),
            'lot_number' => fake()->unique()->bothify('LOT-########'),
            'manufactured_on' => null,
            'expires_on' => null,
            'quantity' => $quantity,
            'unit_purchase_price' => $price,
            'purchase_amount' => $quantity * $price,
        ];
    }
}
