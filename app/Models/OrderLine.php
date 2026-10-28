<?php

namespace App\Models;

use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['line_number', 'product_id', 'product_sku', 'product_name', 'unit_name', 'quantity', 'backordered_initial_quantity', 'backordered_quantity', 'backorder_fulfilled_at', 'unit_sale_price', 'line_amount', 'tax_rate_name', 'tax_rate'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'quantity' => 'decimal:4', 'backordered_initial_quantity' => 'decimal:4', 'backordered_quantity' => 'decimal:4', 'backorder_fulfilled_at' => 'datetime', 'unit_sale_price' => 'decimal:4', 'line_amount' => 'decimal:4', 'tax_rate' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderLineComponent::class);
    }

    public function backorders(): HasMany
    {
        return $this->hasMany(OrderBackorder::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    public function shipmentLines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }
}
