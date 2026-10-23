<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleComponent extends Model
{
    protected $fillable = ['component_product_id', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function bundleProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
