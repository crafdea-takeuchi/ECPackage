<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductImageFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->uuid();

        return [
            'product_id' => Product::factory(), 'disk' => 'product_images',
            'path' => $key.'.webp', 'thumbnail_path' => $key.'-thumb.webp',
            'sort_order' => 0, 'alt_text' => null, 'mime_type' => 'image/webp',
            'size_bytes' => 100, 'width' => 100, 'height' => 100,
        ];
    }
}
