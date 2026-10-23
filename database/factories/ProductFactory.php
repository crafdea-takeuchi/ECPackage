<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return ['sku' => fake()->unique()->bothify('P-########'), 'name' => 'テスト商品', 'unit_id' => Unit::factory(), 'created_by' => User::factory()->admin(), 'registration_status' => 'provisional', 'publication_status' => 'private'];
    }
}
