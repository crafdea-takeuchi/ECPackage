<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))->map(function ($line): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $line['quantity'] = trim((string) ($line['quantity'] ?? ''));
            $line['unit_sale_price'] = trim((string) ($line['unit_sale_price'] ?? ''));

            return $line;
        })->all();
        $this->merge(['notes' => trim((string) $this->input('notes', '')) ?: null, 'lines' => $lines]);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'ordered_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['draft', 'confirm'])],
            'lock_version' => [$this->route('order') ? 'required' : 'nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['nullable', 'integer', Rule::exists('order_lines', 'id')],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('registration_status', 'confirmed')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'lines.*.unit_sale_price' => ['required', 'numeric', 'min:0', 'max:999999999999999.9999', 'decimal:0,4'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('lines')) {
                return;
            }

            $lines = $this->input('lines', []);
            $products = Product::with('unit:id,quantity_scale')->whereIn('id', collect($lines)->pluck('product_id')->filter())->get()->keyBy('id');
            $seen = [];
            $total = '0';
            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId && isset($seen[$productId])) {
                    $validator->errors()->add("lines.$index.product_id", '同じ販売商品が重複しています。数量を1行にまとめてください。');
                }
                $seen[$productId] = true;

                $quantity = (string) ($line['quantity'] ?? '');
                $scale = $products->get($productId)?->unit?->quantity_scale;
                if ($scale !== null && $this->decimalPlaces($quantity) > $scale) {
                    $validator->errors()->add("lines.$index.quantity", $scale === 0 ? 'この販売商品の数量は整数で入力してください。' : "この販売商品の数量は小数{$scale}桁以内で入力してください。");
                }
                $price = (string) ($line['unit_sale_price'] ?? '');
                if (is_numeric($quantity) && is_numeric($price)) {
                    $amount = bcmul($quantity, $price, 4);
                    $total = bcadd($total, $amount, 4);
                    if (bccomp($amount, '999999999999999.9999', 4) === 1) {
                        $validator->errors()->add("lines.$index.quantity", '商品金額が上限を超えています。数量または販売単価を見直してください。');
                    }
                }
            }
            if (bccomp($total, '999999999999999.9999', 4) === 1) {
                $validator->errors()->add('lines', '注文金額の合計が上限を超えています。');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'お客様を選択してください。',
            'customer_id.exists' => 'お客様を選び直してください。',
            'ordered_on.required' => '注文日を入力してください。',
            'ordered_on.date_format' => '注文日を正しい日付で入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
            'action.in' => '保存方法を選び直してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
            'lines.required' => '注文商品を1件以上追加してください。',
            'lines.min' => '注文商品を1件以上追加してください。',
            'lines.max' => '注文商品は100件以内で入力してください。',
            'lines.*.id.exists' => '注文明細を選び直してください。',
            'lines.*.product_id.required' => '販売商品を選択してください。',
            'lines.*.product_id.exists' => '本登録済みの販売商品を選び直してください。',
            'lines.*.quantity.required' => '注文数量を入力してください。',
            'lines.*.quantity.numeric' => '注文数量は数値で入力してください。',
            'lines.*.quantity.gt' => '注文数量は0より大きい数値で入力してください。',
            'lines.*.quantity.decimal' => '注文数量は小数4桁以内で入力してください。',
            'lines.*.unit_sale_price.required' => '販売単価を入力してください。',
            'lines.*.unit_sale_price.numeric' => '販売単価は数値で入力してください。',
            'lines.*.unit_sale_price.min' => '販売単価は0以上で入力してください。',
            'lines.*.unit_sale_price.decimal' => '販売単価は小数4桁以内で入力してください。',
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
