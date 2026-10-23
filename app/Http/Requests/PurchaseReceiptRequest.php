<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PurchaseReceiptRequest extends FormRequest
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
            foreach (['lot_number', 'manufactured_on', 'expires_on', 'quantity', 'unit_purchase_price'] as $field) {
                $line[$field] = trim((string) ($line[$field] ?? ''));
                if ($line[$field] === '' && in_array($field, ['manufactured_on', 'expires_on'], true)) {
                    $line[$field] = null;
                }
            }

            return $line;
        })->all();

        $this->merge(['notes' => trim((string) $this->input('notes', '')) ?: null, 'lines' => $lines]);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['draft', 'post'])],
            'lock_version' => [$this->route('purchase_receipt') ? 'required' : 'nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['nullable', 'integer', Rule::exists('purchase_receipt_lines', 'id')],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereIn('registration_status', ['provisional', 'confirmed'])],
            'lines.*.product_supplier_id' => ['required', 'integer', Rule::exists('product_suppliers', 'id')],
            'lines.*.lot_number' => ['required', 'string', 'max:100'],
            'lines.*.manufactured_on' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'lines.*.unit_purchase_price' => ['required', 'numeric', 'min:0', 'max:999999999999999.9999', 'decimal:0,4'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['supplier_id', 'lines'])) {
                return;
            }

            $supplierId = (int) $this->input('supplier_id');
            $receipt = $this->route('purchase_receipt');
            $supplier = Supplier::find($supplierId);
            if ($supplier && $supplier->status !== 'active' && (! $receipt || $receipt->supplier_id !== $supplierId)) {
                $validator->errors()->add('supplier_id', '有効な仕入先を選択してください。');
            }

            $lines = $this->input('lines', []);
            $products = Product::with('unit:id,quantity_scale')->whereIn('id', collect($lines)->pluck('product_id')->filter())->get()->keyBy('id');
            $settings = ProductSupplier::whereIn('id', collect($lines)->pluck('product_supplier_id')->filter())->get()->keyBy('id');
            $seen = [];

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $productId = (int) ($line['product_id'] ?? 0);
                $setting = $settings->get((int) ($line['product_supplier_id'] ?? 0));
                if (! $setting || $setting->supplier_id !== $supplierId || $setting->product_id !== $productId || $setting->status !== 'active') {
                    $validator->errors()->add("lines.$index.product_id", '選択した仕入先の有効な販売商品を選び直してください。');
                }

                $key = $productId.'|'.mb_strtolower(trim((string) ($line['lot_number'] ?? '')));
                if ($productId && isset($seen[$key])) {
                    $validator->errors()->add("lines.$index.lot_number", '同じ販売商品とロット番号が重複しています。');
                }
                $seen[$key] = true;

                $quantity = (string) ($line['quantity'] ?? '');
                $scale = $products->get($productId)?->unit?->quantity_scale;
                if ($scale !== null && $this->decimalPlaces($quantity) > $scale) {
                    $validator->errors()->add("lines.$index.quantity", $scale === 0 ? 'この販売商品の数量は整数で入力してください。' : "この販売商品の数量は小数{$scale}桁以内で入力してください。");
                }

                $manufactured = $line['manufactured_on'] ?? null;
                $expires = $line['expires_on'] ?? null;
                if ($manufactured && $expires && $expires < $manufactured) {
                    $validator->errors()->add("lines.$index.expires_on", '期限日は製造日以降の日付を入力してください。');
                }

                $price = (string) ($line['unit_purchase_price'] ?? '');
                if (is_numeric($quantity) && is_numeric($price) && bccomp(bcmul($quantity, $price, 4), '999999999999999.9999', 4) === 1) {
                    $validator->errors()->add("lines.$index.quantity", '入庫金額が上限を超えています。数量または仕入単価を見直してください。');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => '仕入先を選択してください。',
            'supplier_id.exists' => '仕入先を選び直してください。',
            'received_on.required' => '入庫日を入力してください。',
            'received_on.date_format' => '入庫日を正しい日付で入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
            'action.in' => '保存方法を選び直してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
            'lines.required' => '入庫商品を1件以上追加してください。',
            'lines.min' => '入庫商品を1件以上追加してください。',
            'lines.max' => '入庫商品は100件以内で入力してください。',
            'lines.*.id.exists' => '入庫明細を選び直してください。',
            'lines.*.product_id.required' => '販売商品を選択してください。',
            'lines.*.product_id.exists' => '販売商品を選び直してください。',
            'lines.*.product_supplier_id.required' => '仕入商品設定を選び直してください。',
            'lines.*.product_supplier_id.exists' => '仕入商品設定を選び直してください。',
            'lines.*.lot_number.required' => 'ロット番号を入力してください。',
            'lines.*.lot_number.max' => 'ロット番号は100文字以内で入力してください。',
            'lines.*.manufactured_on.date_format' => '製造日を正しい日付で入力してください。',
            'lines.*.expires_on.date_format' => '期限日を正しい日付で入力してください。',
            'lines.*.quantity.required' => '入庫数量を入力してください。',
            'lines.*.quantity.numeric' => '入庫数量は数値で入力してください。',
            'lines.*.quantity.gt' => '入庫数量は0より大きい数値で入力してください。',
            'lines.*.quantity.decimal' => '入庫数量は小数4桁以内で入力してください。',
            'lines.*.unit_purchase_price.required' => '仕入単価を入力してください。',
            'lines.*.unit_purchase_price.numeric' => '仕入単価は数値で入力してください。',
            'lines.*.unit_purchase_price.min' => '仕入単価は0以上で入力してください。',
            'lines.*.unit_purchase_price.decimal' => '仕入単価は小数4桁以内で入力してください。',
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
