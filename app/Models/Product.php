<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['sku', 'name', 'kind', 'product_group_id', 'unit_id', 'registration_status', 'publication_status', 'shortage_policy', 'default_sale_price'];

    protected function casts(): array
    {
        return ['default_sale_price' => 'decimal:4', 'attributes' => 'array', 'confirmed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function supplierSettings(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundleComponent::class, 'bundle_product_id')->orderBy('sort_order')->orderBy('id');
    }

    public function usedInBundles(): HasMany
    {
        return $this->hasMany(ProductBundleComponent::class, 'component_product_id');
    }

    public function availableQuantity(): int|float
    {
        if ($this->kind !== 'bundle') {
            return $this->inventoryLots()->where('current_quantity', '>', 0)->sum('current_quantity');
        }
        $this->loadMissing('bundleComponents.componentProduct.inventoryLots');
        if ($this->bundleComponents->isEmpty()) {
            return 0;
        }
        $available = $this->bundleComponents->map(function (ProductBundleComponent $component): string {
            $stock = (string) $component->componentProduct->inventoryLots->sum('current_quantity');

            return bcdiv($stock, (string) $component->quantity, 4);
        })->min();

        return (int) floor((float) $available);
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->using(ProductSupplier::class)
            ->withPivot(['id', 'supplier_product_code', 'default_purchase_price', 'minimum_order_quantity', 'lead_time_days', 'is_primary', 'status'])
            ->withTimestamps();
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
