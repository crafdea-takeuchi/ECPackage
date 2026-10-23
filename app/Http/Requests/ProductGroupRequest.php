<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $attributes = collect($this->input('attributes', []))->map(function ($attribute): mixed {
            if (! is_array($attribute)) {
                return $attribute;
            }
            $attribute['code'] = mb_strtolower(trim((string) ($attribute['code'] ?? '')));
            $attribute['options'] = collect($attribute['options'] ?? [])->map(fn ($option) => trim((string) $option))->filter(fn ($option) => $option !== '')->values()->all();

            return $attribute;
        })->all();
        $this->merge(['attributes' => $attributes]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'lock_version' => [$this->route('product_group') ? 'required' : 'nullable', 'integer', 'min:0'],
            'attributes' => ['present', 'array', 'max:20'],
            'attributes.*.id' => ['nullable', 'integer', Rule::exists('product_attribute_definitions', 'id')],
            'attributes.*.name' => ['required', 'string', 'max:80'],
            'attributes.*.code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'attributes.*.input_type' => ['required', Rule::in(['text', 'number', 'select'])],
            'attributes.*.options' => ['present', 'array', 'max:50'],
            'attributes.*.options.*' => ['string', 'max:100', 'distinct'],
            'attributes.*.is_required' => ['required', 'boolean'],
            'attributes.*.sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'attributes.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $codes = [];
            foreach ($this->input('attributes', []) as $index => $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }
                $code = $attribute['code'] ?? null;
                if ($code && isset($codes[$code])) {
                    $validator->errors()->add("attributes.$index.code", '同じ属性コードが重複しています。');
                }
                if ($code) {
                    $codes[$code] = true;
                }
                if (($attribute['input_type'] ?? null) === 'select' && count($attribute['options'] ?? []) === 0) {
                    $validator->errors()->add("attributes.$index.options", '選択式の属性には選択肢を1つ以上設定してください。');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'name.required' => '基本商品名を入力してください。',
            'name.max' => '基本商品名は150文字以内で入力してください。',
            'description.max' => '説明は2000文字以内で入力してください。',
            'status.in' => '有効または無効を選択してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
            'attributes.max' => '属性は20件以内で設定してください。',
            'attributes.*.id.exists' => '属性を選び直してください。',
            'attributes.*.name.required' => '属性名を入力してください。',
            'attributes.*.name.max' => '属性名は80文字以内で入力してください。',
            'attributes.*.code.required' => '属性コードを入力してください。',
            'attributes.*.code.regex' => '属性コードは小文字の英字から始め、英小文字・数字・アンダースコアで入力してください。',
            'attributes.*.code.max' => '属性コードは50文字以内で入力してください。',
            'attributes.*.input_type.in' => '入力形式を選び直してください。',
            'attributes.*.options.max' => '選択肢は50件以内で設定してください。',
            'attributes.*.options.*.max' => '選択肢は100文字以内で入力してください。',
            'attributes.*.options.*.distinct' => '同じ選択肢が重複しています。',
            'attributes.*.sort_order.integer' => '表示順は0〜999999の整数で入力してください。',
            'attributes.*.sort_order.min' => '表示順は0〜999999の整数で入力してください。',
            'attributes.*.sort_order.max' => '表示順は0〜999999の整数で入力してください。',
        ];
    }
}
