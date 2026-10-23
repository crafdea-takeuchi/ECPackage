<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\RecordStockOperation;
use App\Models\InventoryLot;
use App\Models\OrderBackorder;
use App\Models\User;
use App\Notifications\BackorderReady;
use Illuminate\Support\Facades\DB;

class AllocateBackorders
{
    public function __construct(private RecordStockOperation $recordStockOperation) {}

    /** @param iterable<int> $productIds */
    public function handle(iterable $productIds, User $actor): int
    {
        $ids = collect($productIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $actor): int {
            $backorders = OrderBackorder::query()->whereIn('stock_product_id', $ids)->where('remaining_quantity', '>', 0)
                ->whereHas('orderLine.order', fn ($query) => $query->whereIn('status', ['confirmed', 'preparing']))
                ->with(['orderLine.order.customer:id,name,email'])->orderBy('id')->lockForUpdate()->get()
                ->sortBy(fn (OrderBackorder $item): string => sprintf('%s-%020d-%05d', $item->orderLine->order->ordered_on->format('Y-m-d'), $item->orderLine->order_id, $item->orderLine->line_number))->values();
            $allocatedTotal = 0;

            foreach ($backorders->groupBy(fn (OrderBackorder $item): int => $item->orderLine->order_id) as $items) {
                $order = $items->first()->orderLine->order;
                $stockInputs = [];
                $allocationInputs = [];
                foreach ($items as $backorder) {
                    $remaining = (string) $backorder->remaining_quantity;
                    $lots = InventoryLot::where('product_id', $backorder->stock_product_id)->where('current_quantity', '>', 0)
                        ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')->orderBy('expires_on')->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
                    foreach ($lots as $lot) {
                        if (bccomp($remaining, '0', 4) !== 1) {
                            break;
                        }
                        $quantity = bccomp((string) $lot->current_quantity, $remaining, 4) === -1 ? (string) $lot->current_quantity : $remaining;
                        $stockInputs[] = ['inventory_lot_id' => $lot->id, 'quantity' => $quantity, 'reason' => '取り寄せ再引当 '.$order->order_number];
                        $allocationInputs[] = ['backorder' => $backorder, 'inventory_lot_id' => $lot->id, 'quantity' => $quantity, 'unit_cost' => $lot->unit_purchase_price];
                        $remaining = bcsub($remaining, $quantity, 4);
                        $allocatedTotal++;
                    }
                    $backorder->remaining_quantity = $remaining;
                    if (bccomp($remaining, '0', 4) === 0) {
                        $backorder->fulfilled_at = now();
                    }
                    $backorder->save();
                    if (! $backorder->order_line_component_id) {
                        $backorder->orderLine->backordered_quantity = $remaining;
                        $backorder->orderLine->backorder_fulfilled_at = $backorder->fulfilled_at;
                        $backorder->orderLine->save();
                    }
                }

                if ($stockInputs !== []) {
                    $operation = $this->recordStockOperation->handle(['operation_type' => 'backorder_allocation', 'occurred_on' => today()->format('Y-m-d'), 'notes' => '取り寄せ再引当 '.$order->order_number, 'lines' => $stockInputs], $actor);
                    $operationLines = $operation->lines()->orderBy('line_number')->get()->values();
                    foreach ($allocationInputs as $index => $allocation) {
                        $backorder = $allocation['backorder'];
                        $backorder->orderLine->allocations()->create([
                            'order_line_component_id' => $backorder->order_line_component_id,
                            'order_backorder_id' => $backorder->id,
                            'inventory_lot_id' => $allocation['inventory_lot_id'],
                            'quantity' => $allocation['quantity'], 'unit_cost' => $allocation['unit_cost'],
                            'stock_operation_line_id' => $operationLines[$index]->id,
                        ]);
                    }
                }

                if ($stockInputs !== [] && ! $order->backorders()->where('remaining_quantity', '>', 0)->exists() && ! $order->backorder_completed_at) {
                    $order->backorder_completed_at = now();
                    $order->save();
                    DB::afterCommit(fn () => $order->customer?->notify(new BackorderReady($order)));
                }
            }

            return $allocatedTotal;
        }, 3);
    }
}
