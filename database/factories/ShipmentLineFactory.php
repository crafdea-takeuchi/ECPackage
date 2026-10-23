<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentLineFactory extends Factory
{
    public function definition(): array
    {
        return ['shipment_id' => Shipment::factory(), 'order_line_id' => OrderLine::factory(), 'quantity' => 1];
    }
}
