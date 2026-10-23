<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\RecordStockOperation;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmOrder
{
    public function __construct(private RecordStockOperation $recordStockOperation) {}

    public function handle(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft' || $current->stock_operation_id !== null) {
                throw ValidationException::withMessages(['status' => 'この注文はすでに確定されています。']);
            }

            $stockInputs = [];
            $allocationInputs = [];
            $lines = $current->lines()->with(['product.bundleComponents.componentProduct.unit'])->orderBy('line_number')->get();
            foreach ($lines as $line) {
                $requirements = $this->requirements($line);
                foreach ($requirements as $requirement) {
                    $remaining = $requirement['required_quantity'];
                    $stockProduct = $requirement['product'];
                    $lots = InventoryLot::where('product_id', $stockProduct->id)->where('current_quantity', '>', 0)
                        ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')->orderBy('expires_on')->orderBy('received_on')->orderBy('id')
                        ->lockForUpdate()->get();
                    foreach ($lots as $lot) {
                        if (bccomp($remaining, '0', 4) !== 1) {
                            break;
                        }
                        $allocated = bccomp((string) $lot->current_quantity, $remaining, 4) === -1 ? (string) $lot->current_quantity : $remaining;
                        $stockInputs[] = ['inventory_lot_id' => $lot->id, 'quantity' => $allocated, 'reason' => '注文 '.$current->order_number];
                        $allocationInputs[] = ['order_line_id' => $line->id, 'order_line_component_id' => $requirement['component_id'], 'inventory_lot_id' => $lot->id, 'quantity' => $allocated, 'unit_cost' => $lot->unit_purchase_price];
                        $remaining = bcsub($remaining, $allocated, 4);
                    }

                    if (bccomp($remaining, '0', 4) === 1 && $line->product->shortage_policy !== 'backorder') {
                        $available = bcsub($requirement['required_quantity'], $remaining, 4);
                        $label = $line->product->kind === 'bundle' ? "{$line->product->name}（{$stockProduct->name}）" : $stockProduct->name;
                        throw ValidationException::withMessages(['status' => "{$label} の在庫が不足しています（必要 {$requirement['required_quantity']}、引当可能 {$available}）。"]);
                    }
                    if (bccomp($remaining, '0', 4) === 1) {
                        $backorder = $line->backorders()->create([
                            'order_line_component_id' => $requirement['component_id'],
                            'stock_product_id' => $stockProduct->id,
                            'stock_product_sku' => $stockProduct->sku,
                            'stock_product_name' => $stockProduct->name,
                            'unit_name' => $stockProduct->unit->name,
                            'required_quantity' => $requirement['required_quantity'],
                            'initial_quantity' => $remaining,
                            'remaining_quantity' => $remaining,
                        ]);
                        foreach ($allocationInputs as &$allocation) {
                            if ($allocation['order_line_id'] === $line->id && $allocation['order_line_component_id'] === $requirement['component_id'] && ! isset($allocation['order_backorder_id'])) {
                                $allocation['order_backorder_id'] = $backorder->id;
                            }
                        }
                        unset($allocation);
                        if ($line->product->kind !== 'bundle') {
                            $line->backordered_initial_quantity = $remaining;
                            $line->backordered_quantity = $remaining;
                            $line->save();
                        }
                    }
                }
            }

            $operation = $stockInputs === [] ? null : $this->recordStockOperation->handle([
                'operation_type' => 'outbound', 'occurred_on' => $current->ordered_on->format('Y-m-d'),
                'notes' => '注文確定 '.$current->order_number, 'lines' => $stockInputs,
            ], $actor);
            if ($operation) {
                $operationLines = $operation->lines()->orderBy('line_number')->get()->values();
                foreach ($allocationInputs as $index => $allocation) {
                    $current->lines()->whereKey($allocation['order_line_id'])->firstOrFail()->allocations()->create([
                        ...$allocation, 'order_backorder_id' => $allocation['order_backorder_id'] ?? null,
                        'stock_operation_line_id' => $operationLines[$index]->id,
                    ]);
                }
            }

            $current->status = 'confirmed';
            $current->confirmed_by = $actor->id;
            $current->confirmed_at = now();
            $current->stock_operation_id = $operation?->id;
            $current->save();

            return $current;
        }, 3);
    }

    /** @return array<int, array{product: Product, required_quantity: string, component_id: int|null}> */
    private function requirements(OrderLine $line): array
    {
        if ($line->product->kind !== 'bundle') {
            $line->product->loadMissing('unit');

            return [['product' => $line->product, 'required_quantity' => (string) $line->quantity, 'component_id' => null]];
        }
        if ($line->product->bundleComponents->isEmpty()) {
            throw ValidationException::withMessages(['status' => "{$line->product->name} のセット内容が設定されていません。"]);
        }

        return $line->product->bundleComponents->map(function ($component) use ($line): array {
            $snapshot = $line->components()->create([
                'component_product_id' => $component->componentProduct->id,
                'component_sku' => $component->componentProduct->sku,
                'component_name' => $component->componentProduct->name,
                'unit_name' => $component->componentProduct->unit->name,
                'quantity_per_set' => $component->quantity,
                'required_quantity' => bcmul((string) $line->quantity, (string) $component->quantity, 4),
            ]);

            return ['product' => $component->componentProduct, 'required_quantity' => (string) $snapshot->required_quantity, 'component_id' => $snapshot->id];
        })->all();
    }
}
