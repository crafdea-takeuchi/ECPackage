<?php

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['product_id', 'inventory_lot_id', 'movement_type', 'occurred_on', 'quantity_delta', 'unit_cost', 'source_type', 'source_id', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'quantity_delta' => 'decimal:4', 'unit_cost' => 'decimal:4', 'source_id' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
