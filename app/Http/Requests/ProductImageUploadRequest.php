<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => '画像ファイルを選択してください。',
            'file.file' => '画像をアップロードできませんでした。もう一度お試しください。',
            'file.uploaded' => '画像をアップロードできませんでした。もう一度お試しください。',
            'file.image' => 'JPEG・PNG・WebPの画像を選択してください。',
            'file.mimes' => 'JPEG・PNG・WebPの画像を選択してください。',
            'file.max' => '画像は1枚5MB以内にしてください。',
            'file.dimensions' => '画像の縦横は6000px以内にしてください。',
        ];
    }
}
