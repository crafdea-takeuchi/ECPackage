<?php

namespace App\Models;

use Database\Factories\ProductSupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductSupplier extends Pivot
{
    /** @use HasFactory<ProductSupplierFactory> */
    use HasFactory;

    protected $table = 'product_suppliers';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['product_id', 'supplier_product_code', 'default_purchase_price', 'minimum_order_quantity', 'lead_time_days', 'is_primary', 'status'];

    protected function casts(): array
    {
        return [
            'default_purchase_price' => 'decimal:4',
            'minimum_order_quantity' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
