<?php

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockOperationLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stock_operation_id' => StockOperation::factory(),
            'line_number' => 1,
            'inventory_lot_id' => InventoryLot::factory(),
            'product_id' => Product::factory(),
            'quantity_delta' => -1,
            'quantity_before' => 10,
            'quantity_after' => 9,
            'unit_cost' => 100,
            'reason' => null,
        ];
    }
}
