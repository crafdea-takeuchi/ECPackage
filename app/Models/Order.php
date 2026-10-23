<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['customer_id', 'ordered_on', 'notes'];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date:Y-m-d',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'backorder_completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function stockOperation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class);
    }

    public function cancellationStockOperation(): BelongsTo
    {
        return $this->belongsTo(StockOperation::class, 'cancellation_stock_operation_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function backorders(): HasManyThrough
    {
        return $this->hasManyThrough(OrderBackorder::class, OrderLine::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_number');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->latest('id');
    }
}
