<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['quantity' => trim((string) $this->input('quantity', ''))]);
    }

    public function rules(): array
    {
        return [
            'product_id' => [Rule::requiredIf(! $this->route('product')), 'nullable', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $product = $this->route('product') ?? Product::with('unit:id,quantity_scale')->find($this->integer('product_id'));
            if (! $product || $product->registration_status !== 'confirmed' || $product->publication_status !== 'public' || $product->default_sale_price === null) {
                $validator->errors()->add('product_id', '現在購入できる商品を選び直してください。');

                return;
            }
            $product->loadMissing('unit:id,quantity_scale');
            if ($this->decimalPlaces((string) $this->input('quantity')) > $product->unit->quantity_scale) {
                $validator->errors()->add('quantity', $product->unit->quantity_scale === 0 ? 'この商品の数量は整数で入力してください。' : "この商品の数量は小数{$product->unit->quantity_scale}桁以内で入力してください。");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => '商品を選択してください。',
            'product_id.exists' => '商品を選び直してください。',
            'quantity.required' => '数量を入力してください。',
            'quantity.numeric' => '数量は数値で入力してください。',
            'quantity.gt' => '数量は0より大きい数値で入力してください。',
            'quantity.decimal' => '数量は小数4桁以内で入力してください。',
        ];
    }

    private function decimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($value, '.'), 1), '0'));
    }
}
