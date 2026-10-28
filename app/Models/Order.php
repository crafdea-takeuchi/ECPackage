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

    public const PREFECTURES = [
        '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
        '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
        '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
        '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
        '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
        '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
        '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
    ];

    public const PAYMENT_METHODS = [
        'bank_transfer' => '銀行振込',
    ];

    protected $fillable = [
        'customer_id', 'ordered_on', 'notes',
        'shipping_name', 'shipping_postal_code', 'shipping_prefecture',
        'shipping_address_line1', 'shipping_address_line2', 'payment_method',
    ];

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
