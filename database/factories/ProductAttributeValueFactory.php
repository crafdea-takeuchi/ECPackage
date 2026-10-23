<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductAttributeValueFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'product_attribute_definition_id' => ProductAttributeDefinition::factory(), 'value' => fake()->word()];
    }
}
