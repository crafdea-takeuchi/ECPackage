<?php

namespace App\Actions\Shipments;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmShipment
{
    public function handle(Shipment $shipment, User $actor): Shipment
    {
        return DB::transaction(function () use ($shipment, $actor): Shipment {
            $current = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => '下書きの発送だけ確定できます。']);
            }
            if (! $current->carrier || ! $current->shipped_on) {
                throw ValidationException::withMessages(['status' => '配送会社と発送日を入力してください。']);
            }

            $order = Order::whereKey($current->order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['confirmed', 'preparing'], true)) {
                throw ValidationException::withMessages(['status' => '現在の注文状態では発送を確定できません。']);
            }
            if ($order->backorders()->where('remaining_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['status' => '取り寄せ商品が揃うまで発送を確定できません。']);
            }

            $current->load('lines');
            if ($current->lines->isEmpty()) {
                throw ValidationException::withMessages(['status' => '発送する商品を1件以上入力してください。']);
            }
            $orderLines = $order->lines()->lockForUpdate()->get()->keyBy('id');
            foreach ($current->lines as $shipmentLine) {
                $orderLine = $orderLines->get($shipmentLine->order_line_id);
                if (! $orderLine) {
                    throw ValidationException::withMessages(['status' => '発送明細と注文内容が一致しません。']);
                }
                $alreadyShipped = $orderLine->shipmentLines()->whereHas('shipment', fn ($query) => $query->where('status', 'shipped'))->sum('quantity');
                if (bccomp(bcadd((string) $alreadyShipped, (string) $shipmentLine->quantity, 4), (string) $orderLine->quantity, 4) === 1) {
                    throw ValidationException::withMessages(['status' => "{$orderLine->product_name} の発送数量が注文数量を超えています。"]);
                }
            }

            $current->status = 'shipped';
            $current->shipped_by = $actor->id;
            $current->shipped_at = now();
            $current->lock_version++;
            $current->save();

            $allShipped = $orderLines->every(function ($orderLine): bool {
                $shipped = $orderLine->shipmentLines()->whereHas('shipment', fn ($query) => $query->where('status', 'shipped'))->sum('quantity');

                return bccomp((string) $shipped, (string) $orderLine->quantity, 4) >= 0;
            });
            $order->status = $allShipped ? 'shipped' : 'preparing';
            $order->lock_version++;
            $order->save();

            return $current;
        }, 3);
    }
}
