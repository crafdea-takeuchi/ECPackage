<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\RecordStockOperation;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockOperationRequest;
use App\Models\InventoryLot;
use App\Models\StockOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockOperationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'operation_type' => ['nullable', Rule::in(['outbound', 'adjustment', 'cancellation_return', 'backorder_allocation'])],
            'occurred_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $query = StockOperation::query()->with('creator:id,name')->withCount('lines');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($operation) => $operation->where('operation_number', 'like', '%'.$term.'%')
                ->orWhereHas('lines.inventoryLot', fn ($lot) => $lot->where('lot_number', 'like', '%'.$term.'%'))
                ->orWhereHas('lines.product', fn ($product) => $product->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%')));
        }
        if ($filters['operation_type'] ?? null) {
            $query->where('operation_type', $filters['operation_type']);
        }
        if ($filters['occurred_month'] ?? null) {
            $query->whereBetween('occurred_on', [$filters['occurred_month'].'-01', date('Y-m-t', strtotime($filters['occurred_month'].'-01'))]);
        }

        return Inertia::render('Admin/StockOperations/Index', [
            'operations' => $query->latest('occurred_on')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'counts' => [
                'all' => StockOperation::count(),
                'outbound' => StockOperation::where('operation_type', 'outbound')->count(),
                'adjustment' => StockOperation::where('operation_type', 'adjustment')->count(),
                'cancellation_return' => StockOperation::where('operation_type', 'cancellation_return')->count(),
                'backorder_allocation' => StockOperation::where('operation_type', 'backorder_allocation')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/StockOperations/Create', [
            'lots' => InventoryLot::with(['product.unit:id,name,code,quantity_scale', 'supplier:id,code,name'])
                ->latest('received_on')->latest('id')->limit(500)->get(),
        ]);
    }

    public function store(StockOperationRequest $request, RecordStockOperation $recordStockOperation): RedirectResponse
    {
        $operation = $recordStockOperation->handle($request->validated(), $request->user());

        return to_route('admin.stock-operations.show', $operation)->with('status', $operation->operation_type === 'outbound' ? '出庫を記録し、在庫へ反映しました。' : '在庫調整を記録し、在庫へ反映しました。');
    }

    public function show(StockOperation $stockOperation): Response
    {
        $stockOperation->load(['creator:id,name', 'lines.product.unit:id,name,code,quantity_scale', 'lines.inventoryLot.supplier:id,code,name']);

        return Inertia::render('Admin/StockOperations/Show', ['operation' => $stockOperation]);
    }
}
