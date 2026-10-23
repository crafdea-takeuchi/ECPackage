<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\AllocateBackorders;
use App\Http\Controllers\Controller;
use App\Models\OrderBackorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BackorderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['pending', 'fulfilled', 'cancelled'])]]);
        $query = OrderBackorder::query()->with(['orderLine.order.customer:id,name,email', 'orderLine.product:id,name,sku,kind', 'stockProduct.unit:id,name,quantity_scale']);
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($item) => $item->where('stock_product_name', 'like', '%'.$term.'%')->orWhere('stock_product_sku', 'like', '%'.$term.'%')
                ->orWhereHas('orderLine', fn ($line) => $line->where('product_name', 'like', '%'.$term.'%')->orWhere('product_sku', 'like', '%'.$term.'%')
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', '%'.$term.'%')->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))));
        }
        if (($filters['status'] ?? null) === 'pending') {
            $query->where('remaining_quantity', '>', 0)->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));
        }
        if (($filters['status'] ?? null) === 'fulfilled') {
            $query->whereNotNull('fulfilled_at')->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));
        }
        if (($filters['status'] ?? null) === 'cancelled') {
            $query->whereHas('orderLine.order', fn ($order) => $order->where('status', 'cancelled'));
        }

        return Inertia::render('Admin/Backorders/Index', [
            'lines' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters,
            'counts' => [
                'all' => OrderBackorder::count(),
                'pending' => OrderBackorder::where('remaining_quantity', '>', 0)->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'))->count(),
                'fulfilled' => OrderBackorder::whereNotNull('fulfilled_at')->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'))->count(),
            ],
        ]);
    }

    public function allocate(Request $request, AllocateBackorders $allocateBackorders): RedirectResponse
    {
        $productIds = OrderBackorder::where('remaining_quantity', '>', 0)->distinct()->pluck('stock_product_id');
        $count = $allocateBackorders->handle($productIds, $request->user());

        return back()->with('status', $count > 0 ? "取り寄せ在庫を{$count}件のロットから再引当しました。" : '再引当できる在庫はありませんでした。');
    }
}
