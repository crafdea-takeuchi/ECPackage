<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\StockOperationLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordStockOperation
{
    /** @param array{operation_type: string, occurred_on: string, notes?: string|null, lines: array<int, array{inventory_lot_id: int, quantity: string|int|float, reason?: string|null}>} $data */
    public function handle(array $data, User $actor): StockOperation
    {
        return DB::transaction(function () use ($data, $actor): StockOperation {
            $operation = new StockOperation([
                'operation_type' => $data['operation_type'],
                'occurred_on' => $data['occurred_on'],
                'notes' => $data['notes'] ?? null,
            ]);
            $operation->operation_number = 'SO-'.Str::ulid();
            $operation->created_by = $actor->id;
            $operation->save();

            foreach ($data['lines'] as $index => $input) {
                $lot = InventoryLot::whereKey($input['inventory_lot_id'])->lockForUpdate()->firstOrFail();
                $quantity = (string) $input['quantity'];
                $delta = in_array($data['operation_type'], ['outbound', 'backorder_allocation'], true) ? bcmul($quantity, '-1', 4) : bcadd($quantity, '0', 4);
                $before = (string) $lot->current_quantity;
                $after = bcadd($before, $delta, 4);
                if (bccomp($after, '0', 4) === -1) {
                    throw ValidationException::withMessages([
                        "lines.$index.quantity" => "ロット {$lot->lot_number} の現在庫（{$before}）を超える数量は減らせません。",
                    ]);
                }

                $line = new StockOperationLine([
                    'line_number' => $index + 1,
                    'inventory_lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'quantity_delta' => $delta,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'unit_cost' => $lot->unit_purchase_price,
                    'reason' => $input['reason'] ?? null,
                ]);
                $operation->lines()->save($line);

                $lot->current_quantity = $after;
                $lot->status = bccomp($after, '0', 4) === 0 ? 'depleted' : 'active';
                $lot->save();

                StockMovement::create([
                    'product_id' => $lot->product_id,
                    'inventory_lot_id' => $lot->id,
                    'movement_type' => $data['operation_type'],
                    'occurred_on' => $data['occurred_on'],
                    'quantity_delta' => $delta,
                    'unit_cost' => $lot->unit_purchase_price,
                    'source_type' => 'stock_operation_line',
                    'source_id' => $line->id,
                    'notes' => $input['reason'] ?? $data['notes'] ?? null,
                    'created_by' => $actor->id,
                ]);
            }

            return $operation;
        }, 3);
    }
}
