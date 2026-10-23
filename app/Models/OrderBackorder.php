<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderBackorder extends Model
{
    protected $fillable = ['order_line_component_id', 'stock_product_id', 'stock_product_sku', 'stock_product_name', 'unit_name', 'required_quantity', 'initial_quantity', 'remaining_quantity', 'fulfilled_at'];

    protected function casts(): array
    {
        return ['required_quantity' => 'decimal:4', 'initial_quantity' => 'decimal:4', 'remaining_quantity' => 'decimal:4', 'fulfilled_at' => 'datetime'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function orderLineComponent(): BelongsTo
    {
        return $this->belongsTo(OrderLineComponent::class);
    }

    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
