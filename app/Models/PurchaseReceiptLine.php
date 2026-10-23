<?php

namespace App\Models;

use Database\Factories\PurchaseReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseReceiptLine extends Model
{
    /** @use HasFactory<PurchaseReceiptLineFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['line_number', 'product_id', 'product_supplier_id', 'lot_number', 'manufactured_on', 'expires_on', 'quantity', 'unit_purchase_price', 'purchase_amount'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'manufactured_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'quantity' => 'decimal:4',
            'unit_purchase_price' => 'decimal:4',
            'purchase_amount' => 'decimal:4',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseSetting(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class, 'product_supplier_id');
    }

    public function inventoryLot(): HasOne
    {
        return $this->hasOne(InventoryLot::class);
    }
}
