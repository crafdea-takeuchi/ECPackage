<?php

namespace App\Actions\Shipments;

use App\Models\OrderLine;
use App\Models\Shipment;
use Illuminate\Validation\ValidationException;

class SyncShipmentLines
{
    /**
     * @param  list<array{order_line_id: int, quantity: string|int|float}>  $inputs
     */
    public function handle(Shipment $shipment, array $inputs): void
    {
        $orderLines = OrderLine::query()
            ->where('order_id', $shipment->order_id)
            ->whereIn('id', collect($inputs)->pluck('order_line_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $seen = [];

        foreach ($inputs as $index => $input) {
            $orderLineId = (int) $input['order_line_id'];
            $orderLine = $orderLines->get($orderLineId);

            if ($orderLine === null) {
                throw ValidationException::withMessages([
                    "lines.$index.order_line_id" => 'この注文の商品を選択してください。',
                ]);
            }
            if (isset($seen[$orderLineId])) {
                throw ValidationException::withMessages([
                    "lines.$index.order_line_id" => '同じ注文商品が重複しています。',
                ]);
            }
            $seen[$orderLineId] = true;

            $reserved = $orderLine->shipmentLines()
                ->where('shipment_id', '!=', $shipment->id)
                ->whereHas('shipment', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->sum('quantity');
            $remaining = bcsub((string) $orderLine->quantity, (string) $reserved, 4);

            if (bccomp((string) $input['quantity'], $remaining, 4) === 1) {
                throw ValidationException::withMessages([
                    "lines.$index.quantity" => "未発送数量（{$remaining} {$orderLine->unit_name}）以内で入力してください。",
                ]);
            }
        }

        $shipment->lines()->delete();

        foreach ($inputs as $input) {
            $shipment->lines()->create([
                'order_line_id' => $input['order_line_id'],
                'quantity' => $input['quantity'],
            ]);
        }
    }
}
