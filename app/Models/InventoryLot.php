<?php

namespace App\Models;

use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['product_id', 'supplier_id', 'purchase_receipt_line_id', 'lot_number', 'received_on', 'manufactured_on', 'expires_on', 'initial_quantity', 'current_quantity', 'unit_purchase_price', 'status'];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'manufactured_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'initial_quantity' => 'decimal:4',
            'current_quantity' => 'decimal:4',
            'unit_purchase_price' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }

    public function operationLines(): HasMany
    {
        return $this->hasMany(StockOperationLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
