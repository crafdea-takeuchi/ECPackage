<?php

namespace App\Models;

use Database\Factories\StockOperationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockOperationLine extends Model
{
    /** @use HasFactory<StockOperationLineFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['line_number', 'inventory_lot_id', 'product_id', 'quantity_delta', 'quantity_before', 'quantity_after', 'unit_cost', 'reason'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_delta' => 'decimal:4',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'stock_operation_id');
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'source_id')->where('source_type', 'stock_operation_line');
    }
}
