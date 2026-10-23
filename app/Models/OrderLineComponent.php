<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLineComponent extends Model
{
    protected $fillable = ['component_product_id', 'component_sku', 'component_name', 'unit_name', 'quantity_per_set', 'required_quantity'];

    protected function casts(): array
    {
        return ['quantity_per_set' => 'decimal:4', 'required_quantity' => 'decimal:4'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
