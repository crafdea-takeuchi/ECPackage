<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\AllocateBackorders;
use App\Http\Controllers\Controller;
use App\Models\OrderBackorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackorderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['unrequested', 'pending', 'fulfilled', 'cancelled'])],
        ]);
        $query = OrderBackorder::query()->with([
            'orderLine.order.customer:id,name,email',
            'orderLine.product:id,name,sku,kind',
            'stockProduct.unit:id,name,quantity_scale',
            'requester:id,name',
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($item) => $item->where('stock_product_name', 'like', '%'.$term.'%')->orWhere('stock_product_sku', 'like', '%'.$term.'%')
                ->orWhereHas('orderLine', fn ($line) => $line->where('product_name', 'like', '%'.$term.'%')->orWhere('product_sku', 'like', '%'.$term.'%')
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', '%'.$term.'%')->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))));
        }
        if (($filters['status'] ?? null) === 'unrequested') {
            $query->where('remaining_quantity', '>', 0)->whereNull('requested_at')
                ->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));
        }
        if (($filters['status'] ?? null) === 'pending') {
            $query->where('remaining_quantity', '>', 0)->whereNotNull('requested_at')
                ->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));
        }
        if (($filters['status'] ?? null) === 'fulfilled') {
            $query->whereNotNull('fulfilled_at')
                ->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));
        }
        if (($filters['status'] ?? null) === 'cancelled') {
            $query->whereHas('orderLine.order', fn ($order) => $order->where('status', 'cancelled'));
        }

        $active = fn ($query) => $query->whereHas('orderLine.order', fn ($order) => $order->whereNot('status', 'cancelled'));

        return Inertia::render('Admin/Backorders/Index', [
            'lines' => $query->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'counts' => [
                'all' => OrderBackorder::count(),
                'unrequested' => $active(OrderBackorder::query()->where('remaining_quantity', '>', 0)->whereNull('requested_at'))->count(),
                'pending' => $active(OrderBackorder::query()->where('remaining_quantity', '>', 0)->whereNotNull('requested_at'))->count(),
                'fulfilled' => $active(OrderBackorder::query()->whereNotNull('fulfilled_at'))->count(),
            ],
        ]);
    }

    public function markRequested(Request $request, OrderBackorder $orderBackorder): RedirectResponse
    {
        $changed = DB::transaction(function () use ($request, $orderBackorder): bool {
            $current = OrderBackorder::query()
                ->with('orderLine.order:id,status')
                ->lockForUpdate()
                ->findOrFail($orderBackorder->id);

            if ($current->orderLine->order->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'キャンセル済みの注文は依頼済みにできません。']);
            }
            if (bccomp((string) $current->remaining_quantity, '0', 4) !== 1) {
                throw ValidationException::withMessages(['status' => '入荷済みの取り寄せは依頼済みにできません。']);
            }
            if ($current->requested_at !== null) {
                return false;
            }

            $current->forceFill([
                'requested_at' => now(),
                'requested_by' => $request->user()->id,
            ])->save();

            return true;
        }, 3);

        return back()->with('status', $changed ? '取り寄せ依頼を依頼済みにしました。' : 'この取り寄せ依頼はすでに依頼済みです。');
    }

    public function allocate(Request $request, AllocateBackorders $allocateBackorders): RedirectResponse
    {
        $productIds = OrderBackorder::where('remaining_quantity', '>', 0)->distinct()->pluck('stock_product_id');
        $count = $allocateBackorders->handle($productIds, $request->user());

        return back()->with('status', $count > 0 ? "取り寄せ在庫を{$count}件のロットから再引当しました。" : '再引当できる在庫はありませんでした。');
    }
}
