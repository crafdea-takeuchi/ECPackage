<?php

namespace Database\Factories;

use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductAttributeDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return ['product_group_id' => ProductGroup::factory(), 'name' => '味', 'code' => fake()->unique()->lexify('attr_??????'), 'input_type' => 'text', 'options' => null, 'is_required' => false, 'sort_order' => 0, 'status' => 'active'];
    }
}
