<?php

namespace Database\Factories;

use App\Models\ProductImageUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImageUpload>
 */
class ProductImageUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'created_by' => User::factory()->admin(),
            'disk' => 'product_images',
            'path' => $key.'.webp',
            'thumbnail_path' => $key.'-thumb.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 1024,
            'width' => 800,
            'height' => 600,
        ];
    }
}
