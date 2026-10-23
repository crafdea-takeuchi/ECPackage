<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))->map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $line['quantity'] = trim((string) ($line['quantity'] ?? ''));

            return $line;
        })->all();
        $this->merge([
            'carrier' => trim((string) $this->input('carrier', '')) ?: null,
            'tracking_number' => trim((string) $this->input('tracking_number', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')) ?: null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $shipment = $this->route('shipment');

        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')],
            'action' => ['required', Rule::in(['draft', 'ship'])],
            'carrier' => [Rule::requiredIf($this->input('action') === 'ship'), 'nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'scheduled_on' => ['nullable', 'date'],
            'shipped_on' => [Rule::requiredIf($this->input('action') === 'ship'), 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lock_version' => [$shipment ? 'required' : 'nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_line_id' => ['required', 'integer', 'distinct', Rule::exists('order_lines', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['order_id', 'lines'])) {
                return;
            }
            $order = Order::with(['lines.product.unit', 'lines.shipmentLines.shipment'])->find($this->integer('order_id'));
            if (! $order || ! in_array($order->status, ['confirmed', 'preparing'], true)) {
                $validator->errors()->add('order_id', '発送準備ができる注文を選択してください。');

                return;
            }
            $currentShipmentId = $this->route('shipment')?->id;
            $orderLines = $order->lines->keyBy('id');
            foreach ($this->input('lines', []) as $index => $input) {
                if (! is_array($input)) {
                    continue;
                }
                $line = $orderLines->get((int) ($input['order_line_id'] ?? 0));
                if (! $line) {
                    $validator->errors()->add("lines.$index.order_line_id", 'この注文の商品を選択してください。');

                    continue;
                }
                $quantity = (string) ($input['quantity'] ?? '');
                if (is_numeric($quantity) && $this->decimalPlaces($quantity) > $line->product->unit->quantity_scale) {
                    $validator->errors()->add("lines.$index.quantity", $line->product->unit->quantity_scale === 0 ? 'この商品の発送数量は整数で入力してください。' : "この商品の発送数量は小数{$line->product->unit->quantity_scale}桁以内で入力してください。");
                }
                if (! is_numeric($quantity)) {
                    continue;
                }
                $reserved = $line->shipmentLines
                    ->filter(fn ($shipmentLine): bool => $shipmentLine->shipment->status !== 'cancelled' && $shipmentLine->shipment_id !== $currentShipmentId)
                    ->sum('quantity');
                $remaining = bcsub((string) $line->quantity, (string) $reserved, 4);
                if (bccomp($quantity, $remaining, 4) === 1) {
                    $validator->errors()->add("lines.$index.quantity", "未発送数量（{$remaining} {$line->unit_name}）以内で入力してください。");
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => '注文を選択してください。',
            'order_id.exists' => '注文を選び直してください。',
            'carrier.required' => '発送確定時は配送会社を入力してください。',
            'carrier.max' => '配送会社は100文字以内で入力してください。',
            'tracking_number.max' => '追跡番号は100文字以内で入力してください。',
            'shipped_on.required' => '発送日を入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
            'lines.required' => '発送する商品を入力してください。',
            'lines.min' => '発送する商品を1件以上入力してください。',
            'lines.*.order_line_id.distinct' => '同じ注文商品が重複しています。',
            'lines.*.quantity.gt' => '発送数量は0より大きい数値で入力してください。',
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
