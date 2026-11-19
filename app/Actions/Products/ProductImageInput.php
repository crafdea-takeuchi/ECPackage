<?php

namespace App\Actions\Products;

use Illuminate\Validation\Rule;

class ProductImageInput
{
    public static function rules(string $ownerColumn, ?int $ownerId, int $max): array
    {
        return [
            'images_present' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'list', 'max:'.$max],
            'images.*' => ['array:id,file,alt_text'],
            'images.*.id' => ['nullable', 'integer', Rule::exists('product_images', 'id')->where($ownerColumn, $ownerId ?? 0)],
            'images.*.file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'images.*.alt_text' => ['nullable', 'string', 'max:200'],
        ];
    }
}
