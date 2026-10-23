<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['code', 'name', 'contact_name', 'email', 'phone', 'postal_code', 'address', 'notes', 'status'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function purchaseSettings(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_suppliers')
            ->using(ProductSupplier::class)
            ->withPivot(['id', 'supplier_product_code', 'default_purchase_price', 'minimum_order_quantity', 'lead_time_days', 'is_primary', 'status'])
            ->withTimestamps();
    }
}
