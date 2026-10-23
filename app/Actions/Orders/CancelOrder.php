<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\RecordStockOperation;
use App\Models\Order;
use App\Models\OrderBackorder;
use App\Models\OrderSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    public function __construct(private RecordStockOperation $recordStockOperation) {}

    public function handle(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason): Order {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, OrderSetting::current()->cancellable_statuses, true)) {
                throw ValidationException::withMessages(['status' => '現在の注文状態ではキャンセルできません。']);
            }
            if ($current->shipments()->where('status', 'shipped')->exists()) {
                throw ValidationException::withMessages(['status' => '発送済みの伝票がある注文はキャンセルできません。']);
            }
            if ($current->cancelled_at) {
                throw ValidationException::withMessages(['status' => 'この注文はすでにキャンセルされています。']);
            }

            $allocations = $current->lines()->with('allocations')->get()->flatMap->allocations;
            $operation = $allocations->isEmpty() ? null : $this->recordStockOperation->handle([
                'operation_type' => 'cancellation_return',
                'occurred_on' => today()->format('Y-m-d'),
                'notes' => '注文キャンセル '.$current->order_number,
                'lines' => $allocations->map(fn ($allocation): array => [
                    'inventory_lot_id' => $allocation->inventory_lot_id,
                    'quantity' => $allocation->quantity,
                    'reason' => '注文キャンセル '.$current->order_number,
                ])->values()->all(),
            ], $actor);

            $current->shipments()->where('status', 'draft')->update([
                'status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(),
                'cancellation_reason' => '注文キャンセル：'.$reason,
            ]);
            $current->lines()->where('backordered_quantity', '>', 0)->update(['backordered_quantity' => 0]);
            OrderBackorder::whereHas('orderLine', fn ($query) => $query->where('order_id', $current->id))->where('remaining_quantity', '>', 0)->update(['remaining_quantity' => 0]);
            $current->status = 'cancelled';
            $current->cancelled_by = $actor->id;
            $current->cancelled_at = now();
            $current->cancellation_reason = $reason;
            $current->cancellation_stock_operation_id = $operation?->id;
            $current->lock_version++;
            $current->save();

            return $current;
        }, 3);
    }
}
