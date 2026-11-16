<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\DisposeExpiredInventory;
use App\Actions\Inventory\InventoryExpiration;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisposeExpiredInventoryRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLotController extends Controller
{
    public function index(Request $request, InventoryExpiration $expiration): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'received_month' => ['nullable', 'date_format:Y-m'],
            'availability' => ['nullable', Rule::in(['available', 'depleted'])],
            'expiration_type' => ['nullable', Rule::in(['best_before', 'use_by', 'none'])],
            'expiration_status' => ['nullable', Rule::in(['expired', 'today', 'near', 'valid', 'none'])],
            'expiration_from' => ['nullable', 'date_format:Y-m-d'],
            'expiration_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('expiration_from') ? ['after_or_equal:expiration_from'] : [])],
            'expiration_days' => ['nullable', 'required_if:expiring_only,1', 'integer', 'min:1', 'max:'.$expiration->maxDays()],
            'expiring_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['received_desc', 'expiration_asc'])],
        ], [
            'expiration_days.required_if' => '期限までの日数を入力してください。',
            'expiration_days.integer' => '期限までの日数は整数で入力してください。',
            'expiration_days.min' => '期限までの日数は1以上で入力してください。',
            'expiration_days.max' => '期限までの日数は'.$expiration->maxDays().'以下で入力してください。',
            'expiration_from.date_format' => '期限日Fromは正しい日付で入力してください。',
            'expiration_to.date_format' => '期限日Toは正しい日付で入力してください。',
            'expiration_to.after_or_equal' => '期限日Toは期限日From以降の日付で入力してください。',
            'expiration_type.in' => '期限種別を選び直してください。',
            'expiration_status.in' => '期限状態を選び直してください。',
        ]);
        $filters['expiration_days'] = (int) ($filters['expiration_days'] ?? $expiration->warningDays());
        $filters['sort'] = $filters['sort'] ?? 'received_desc';
        $query = $expiration->filter($this->applyFilters(InventoryLot::query(), $filters), $filters);
        $totals = (clone $query)
            ->join('products', 'products.id', '=', 'inventory_lots.product_id')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->selectRaw('units.id, units.code, units.name, units.quantity_scale, SUM(inventory_lots.current_quantity) as total_quantity')
            ->groupBy('units.id', 'units.code', 'units.name', 'units.quantity_scale')
            ->orderBy('units.id')->get();

        $lots = $expiration->order($query, $filters['sort'], $filters['expiration_days'])
            ->with(['product.unit:id,name,code,quantity_scale', 'supplier:id,code,name', 'receiptLine.receipt:id,receipt_number'])
            ->paginate(20)->withQueryString();
        $canDispose = $request->user()->isAdmin();
        $lots->getCollection()->each(function (InventoryLot $lot) use ($expiration, $filters, $canDispose): void {
            $lot->setAttribute('expiration', $expiration->describe($lot, $filters['expiration_days']));
            $lot->setAttribute('can_dispose', $canDispose && DisposeExpiredInventory::eligible($lot));
        });

        return Inertia::render('Admin/InventoryLots/Index', [
            'lots' => $lots,
            'disposal' => ['allowed' => $canDispose, 'reasons' => DisposeExpiredInventory::REASONS, 'notes' => DisposeExpiredInventory::DEFAULT_NOTES],
            'expirationSettings' => ['warning_days' => $expiration->warningDays(), 'max_days' => $expiration->maxDays(), 'today' => $expiration->today->toDateString()],
            'filters' => $filters,
            'products' => Product::whereIn('registration_status', ['provisional', 'confirmed'])->orderBy('name')->get(['id', 'sku', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'totalsByUnit' => $totals,
            'counts' => ['all' => InventoryLot::count(), 'available' => InventoryLot::where('current_quantity', '>', 0)->count()],
        ]);
    }

    public function dispose(DisposeExpiredInventoryRequest $request, DisposeExpiredInventory $dispose): RedirectResponse
    {
        $result = $dispose->handle($request->validated(), $request->user());
        $totals = array_map(fn (array $total): string => rtrim(rtrim($total['quantity'], '0'), '.').$total['unit'], $result['totals']);

        return to_route('admin.inventory-lots.index', $request->query())
            ->with('status', $result['lot_count'].'ロット、合計'.implode('・', $totals).'を廃棄しました。');
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($lot) => $lot->where('inventory_lots.lot_number', 'like', '%'.$term.'%')->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%')));
        }
        if ($filters['product_id'] ?? null) {
            $query->where('inventory_lots.product_id', $filters['product_id']);
        }
        if ($filters['supplier_id'] ?? null) {
            $query->where('inventory_lots.supplier_id', $filters['supplier_id']);
        }
        if ($filters['received_month'] ?? null) {
            $query->whereBetween('inventory_lots.received_on', [$filters['received_month'].'-01', date('Y-m-t', strtotime($filters['received_month'].'-01'))]);
        }
        if (($filters['availability'] ?? null) === 'available') {
            $query->where('inventory_lots.current_quantity', '>', 0);
        }
        if (($filters['availability'] ?? null) === 'depleted') {
            $query->where('inventory_lots.current_quantity', '<=', 0);
        }

        return $query;
    }
}
