<?php

namespace App\Actions\Products;

use Illuminate\Validation\Rule;

class ProductImageInput
{
    /**
     * 画像入力の検証ルール。$uploaderId を渡したときだけ、その管理者が事前にアップロードした画像（upload_id）を受け付ける。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $ownerColumn, ?int $ownerId, int $max, ?int $uploaderId = null): array
    {
        return [
            'images_present' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'list', 'max:'.$max],
            'images.*' => ['array:id,file,upload_id,alt_text'],
            'images.*.id' => ['nullable', 'integer', Rule::exists('product_images', 'id')->where($ownerColumn, $ownerId ?? 0)],
            'images.*.file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'images.*.upload_id' => $uploaderId === null
                ? ['prohibited']
                : ['nullable', 'integer', 'distinct', 'prohibits:images.*.file', Rule::exists('product_image_uploads', 'id')->where('created_by', $uploaderId)],
            'images.*.alt_text' => ['nullable', 'string', 'max:200'],
        ];
    }
}
