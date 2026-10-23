<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderLineFactory extends Factory
{
    public function definition(): array
    {
        $quantity = 1;
        $price = 100;

        return [
            'order_id' => Order::factory(),
            'line_number' => 1,
            'product_id' => Product::factory(),
            'product_sku' => fake()->unique()->bothify('P-########'),
            'product_name' => 'テスト商品',
            'unit_name' => '個',
            'quantity' => $quantity,
            'unit_sale_price' => $price,
            'line_amount' => $quantity * $price,
        ];
    }
}
