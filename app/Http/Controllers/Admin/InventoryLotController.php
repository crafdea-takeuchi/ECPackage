<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLotController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'received_month' => ['nullable', 'date_format:Y-m'],
            'availability' => ['nullable', Rule::in(['available', 'depleted'])],
        ]);
        $query = $this->applyFilters(InventoryLot::query(), $filters);
        $totals = (clone $query)
            ->join('products', 'products.id', '=', 'inventory_lots.product_id')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->selectRaw('units.id, units.code, units.name, units.quantity_scale, SUM(inventory_lots.current_quantity) as total_quantity')
            ->groupBy('units.id', 'units.code', 'units.name', 'units.quantity_scale')
            ->orderBy('units.id')->get();

        return Inertia::render('Admin/InventoryLots/Index', [
            'lots' => $query->with(['product.unit:id,name,code,quantity_scale', 'supplier:id,code,name', 'receiptLine.receipt:id,receipt_number'])->latest('received_on')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'products' => Product::whereIn('registration_status', ['provisional', 'confirmed'])->orderBy('name')->get(['id', 'sku', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'totalsByUnit' => $totals,
            'counts' => ['all' => InventoryLot::count(), 'available' => InventoryLot::where('current_quantity', '>', 0)->count()],
        ]);
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
