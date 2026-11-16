<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class InventoryExpiration
{
    public readonly CarbonImmutable $today;

    public function __construct()
    {
        $this->today = CarbonImmutable::today('Asia/Tokyo');
    }

    public function warningDays(): int
    {
        return max(1, min($this->maxDays(), (int) config('inventory.expiration_warning_days', 30)));
    }

    public function maxDays(): int
    {
        return (int) config('inventory.expiration_filter_max_days', 3650);
    }

    /** @param array<string, mixed> $filters */
    public function filter(Builder $query, array $filters): Builder
    {
        $date = 'inventory_lots.expires_on';
        $today = $this->today->toDateString();
        $cutoff = $this->today->addDays((int) $filters['expiration_days'])->toDateString();
        $type = $filters['expiration_type'] ?? null;
        if ($type === 'none') {
            $query->whereNull('inventory_lots.expiration_type')->whereNull($date);
        } elseif ($type) {
            $query->where('inventory_lots.expiration_type', $type);
        }

        match ($filters['expiration_status'] ?? null) {
            'expired' => $query->where($date, '<', $today),
            'today' => $query->where($date, $today),
            'near' => $query->where($date, '>', $today)->where($date, '<=', $cutoff),
            'valid' => $query->where($date, '>', $cutoff),
            'none' => $query->whereNull($date),
            default => null,
        };
        if ($filters['expiration_from'] ?? null) {
            $query->where($date, '>=', $filters['expiration_from']);
        }
        if ($filters['expiration_to'] ?? null) {
            $query->where($date, '<=', $filters['expiration_to']);
        }
        if ($filters['expiring_only'] ?? false) {
            $query->whereBetween($date, [$today, $cutoff]);
        }

        return $query;
    }

    public function order(Builder $query, string $sort, int $days): Builder
    {
        if ($sort !== 'expiration_asc') {
            return $query->latest('inventory_lots.received_on')->latest('inventory_lots.id');
        }

        $today = $this->today->toDateString();
        $cutoff = $this->today->addDays($days)->toDateString();

        return $query
            ->orderByRaw('CASE WHEN inventory_lots.current_quantity > 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inventory_lots.expires_on < ? THEN 0 WHEN inventory_lots.expires_on = ? THEN 1 WHEN inventory_lots.expires_on <= ? THEN 2 WHEN inventory_lots.expires_on IS NOT NULL THEN 3 ELSE 4 END', [$today, $today, $cutoff])
            ->orderBy('inventory_lots.expires_on')
            ->orderBy('inventory_lots.received_on')
            ->orderBy(Product::select('sku')->whereColumn('products.id', 'inventory_lots.product_id')->limit(1))
            ->orderBy('inventory_lots.lot_number')->orderBy('inventory_lots.id');
    }

    /** @return array{type_label: string, days: ?int, remaining_label: string, status: string, status_label: string} */
    public function describe(InventoryLot $lot, int $warningDays): array
    {
        $days = $lot->expires_on
            ? (int) $this->today->diffInDays(CarbonImmutable::parse($lot->expires_on->format('Y-m-d'), 'Asia/Tokyo'))
            : null;
        $status = match (true) {
            $days === null => 'none',
            $days < 0 => 'expired',
            $days === 0 => 'today',
            $days <= $warningDays => 'near',
            default => 'valid',
        };

        return [
            'type_label' => match ($lot->expiration_type) {
                'best_before' => '賞味期限',
                'use_by' => '消費期限',
                default => $days === null ? '期限なし' : '種別未設定',
            },
            'days' => $days,
            'remaining_label' => match (true) {
                $days === null => '—',
                $days < 0 => abs($days).'日超過',
                $days === 0 => '本日期限',
                default => '残り'.$days.'日',
            },
            'status' => $status,
            'status_label' => match ($status) {
                'expired' => '期限切れ',
                'today' => '本日期限',
                'near' => '期限間近',
                'valid' => '期限内',
                default => '期限なし',
            },
        ];
    }
}
