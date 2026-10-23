<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'lock_version' => [$this->route('category') ? 'required' : 'nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '商品分類名を入力してください。',
            'name.max' => '商品分類名は100文字以内で入力してください。',
            'parent_id.exists' => '親分類を選び直してください。',
            'sort_order.required' => '表示順を入力してください。',
            'sort_order.integer' => '表示順は0〜999999の整数で入力してください。',
            'sort_order.min' => '表示順は0〜999999の整数で入力してください。',
            'sort_order.max' => '表示順は0〜999999の整数で入力してください。',
            'status.in' => '有効または無効を選択してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
        ];
    }
}
