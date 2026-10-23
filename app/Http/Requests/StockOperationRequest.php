<?php

namespace App\Http\Requests;

use App\Models\InventoryLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockOperationRequest extends FormRequest
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
            $line['reason'] = trim((string) ($line['reason'] ?? '')) ?: null;

            return $line;
        })->all();

        $this->merge(['notes' => trim((string) $this->input('notes', '')) ?: null, 'lines' => $lines]);
    }

    public function rules(): array
    {
        return [
            'operation_type' => ['required', Rule::in(['outbound', 'adjustment'])],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.inventory_lot_id' => ['required', 'integer', Rule::exists('inventory_lots', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'not_in:0,0.0,0.00,0.000,0.0000', 'min:-999999999999999.9999', 'max:999999999999999.9999'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['operation_type', 'lines'])) {
                return;
            }

            $type = (string) $this->input('operation_type');
            $lines = $this->input('lines', []);
            $lots = InventoryLot::with('product.unit:id,quantity_scale')->whereIn('id', collect($lines)->pluck('inventory_lot_id')->filter())->get()->keyBy('id');
            $seen = [];
            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $lotId = (int) ($line['inventory_lot_id'] ?? 0);
                if ($lotId && isset($seen[$lotId])) {
                    $validator->errors()->add("lines.$index.inventory_lot_id", '同じロットが重複しています。');
                }
                $seen[$lotId] = true;

                $quantity = (string) ($line['quantity'] ?? '');
                if ($type === 'outbound' && is_numeric($quantity) && bccomp($quantity, '0', 4) !== 1) {
                    $validator->errors()->add("lines.$index.quantity", '出庫数量は0より大きい数値で入力してください。');
                }
                $scale = $lots->get($lotId)?->product?->unit?->quantity_scale;
                if ($scale !== null && $this->decimalPlaces($quantity) > $scale) {
                    $validator->errors()->add("lines.$index.quantity", $scale === 0 ? 'この販売商品の数量は整数で入力してください。' : "この販売商品の数量は小数{$scale}桁以内で入力してください。");
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'operation_type.required' => '処理区分を選択してください。',
            'operation_type.in' => '処理区分を選び直してください。',
            'occurred_on.required' => '処理日を入力してください。',
            'occurred_on.date_format' => '処理日を正しい日付で入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
            'lines.required' => '対象ロットを1件以上追加してください。',
            'lines.min' => '対象ロットを1件以上追加してください。',
            'lines.max' => '対象ロットは100件以内で入力してください。',
            'lines.*.inventory_lot_id.required' => '対象ロットを選択してください。',
            'lines.*.inventory_lot_id.exists' => '対象ロットを選び直してください。',
            'lines.*.quantity.required' => '数量を入力してください。',
            'lines.*.quantity.numeric' => '数量は数値で入力してください。',
            'lines.*.quantity.decimal' => '数量は小数4桁以内で入力してください。',
            'lines.*.quantity.not_in' => '数量は0以外で入力してください。',
            'lines.*.quantity.min' => '数量が入力可能な範囲を下回っています。',
            'lines.*.quantity.max' => '数量が入力可能な範囲を超えています。',
            'lines.*.reason.max' => '理由は500文字以内で入力してください。',
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
