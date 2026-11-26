<?php

namespace App\Actions\Products;

use Illuminate\Validation\Rule;

class ProductImageInput
{
    /**
     * 画像1枚の上限（5MB）。OSの表示（1MB = 1,000,000バイト）と食い違わないようバイトで定める。
     */
    public const MAX_FILE_BYTES = 5_000_000;

    /**
     * Laravelの max ルールはファイルを「バイト数 / 1024」で比べるため、その単位に換算した値を返す。
     */
    public static function maxFileRule(): string
    {
        return 'max:'.(self::MAX_FILE_BYTES / 1024);
    }

    /**
     * 保存時に画像ファイルをまとめて送る画面（基本商品）の検証ルール。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $ownerColumn, ?int $ownerId, int $max): array
    {
        return [
            'images_present' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'list', 'max:'.$max],
            'images.*' => ['array:id,file,alt_text'],
            'images.*.id' => ['nullable', 'integer', Rule::exists('product_images', 'id')->where($ownerColumn, $ownerId ?? 0)],
            'images.*.file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', self::maxFileRule(), 'dimensions:max_width=6000,max_height=6000'],
            'images.*.alt_text' => ['nullable', 'string', 'max:200'],
        ];
    }
}
