<?php

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\OrderLine;
use App\Models\StockOperationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'inventory_lot_id' => InventoryLot::factory(),
            'stock_operation_line_id' => StockOperationLine::factory(),
            'quantity' => 1,
            'unit_cost' => 100,
        ];
    }
}
