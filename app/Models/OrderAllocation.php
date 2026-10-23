<?php

namespace App\Models;

use Database\Factories\OrderAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAllocation extends Model
{
    /** @use HasFactory<OrderAllocationFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['order_line_component_id', 'order_backorder_id', 'inventory_lot_id', 'stock_operation_line_id', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function orderLineComponent(): BelongsTo
    {
        return $this->belongsTo(OrderLineComponent::class);
    }

    public function backorder(): BelongsTo
    {
        return $this->belongsTo(OrderBackorder::class, 'order_backorder_id');
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function stockOperationLine(): BelongsTo
    {
        return $this->belongsTo(StockOperationLine::class);
    }
}
