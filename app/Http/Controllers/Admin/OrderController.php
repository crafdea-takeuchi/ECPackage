<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\UpdateOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'status' => ['nullable', Rule::in(['draft', 'confirmed', 'preparing', 'shipped', 'completed', 'cancelled'])],
            'ordered_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $query = Order::query()->with('customer:id,name,email')->withCount('lines');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($order) => $order->where('order_number', 'like', '%'.$term.'%')
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))
                ->orWhereHas('lines', fn ($line) => $line->where('product_name', 'like', '%'.$term.'%')->orWhere('product_sku', 'like', '%'.$term.'%')));
        }
        if ($filters['customer_id'] ?? null) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['ordered_month'] ?? null) {
            $query->whereBetween('ordered_on', [$filters['ordered_month'].'-01', date('Y-m-t', strtotime($filters['ordered_month'].'-01'))]);
        }

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $query->latest('ordered_on')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'customers' => $this->customers(),
            'counts' => ['all' => Order::count(), 'draft' => Order::where('status', 'draft')->count(), 'active' => Order::whereIn('status', ['confirmed', 'preparing', 'shipped'])->count(), 'completed' => Order::where('status', 'completed')->count(), 'cancelled' => Order::where('status', 'cancelled')->count()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Orders/Form', ['order' => null, 'customers' => $this->customers(), 'products' => $this->products()]);
    }

    public function edit(Order $order): Response
    {
        $order->load(['customer:id,name,email', 'lines.components', 'lines.backorders', 'lines.allocations.inventoryLot:id,lot_number', 'lines.allocations.orderLineComponent:id,component_name,unit_name', 'stockOperation:id,operation_number', 'cancellationStockOperation:id,operation_number', 'shipments.lines']);

        return Inertia::render('Admin/Orders/Form', [
            'order' => $order,
            'customers' => $this->customers($order),
            'products' => $this->products($order),
            'cancellableStatuses' => OrderSetting::current()->cancellable_statuses,
        ]);
    }

    public function store(OrderRequest $request, ConfirmOrder $confirmOrder): RedirectResponse
    {
        $order = DB::transaction(function () use ($request, $confirmOrder): Order {
            $order = new Order($request->safe()->only(['customer_id', 'ordered_on', 'notes']));
            $order->order_number = 'O-'.Str::ulid();
            $order->order_source = 'admin';
            $order->status = 'draft';
            $order->created_by = $request->user()->id;
            $order->save();
            $this->syncLines($request, $order);
            if ($request->validated('action') === 'confirm') {
                $confirmOrder->handle($order, $request->user());
            }

            return $order;
        }, 3);

        return to_route('admin.orders.edit', $order)->with('status', $order->fresh()->status === 'confirmed' ? '注文を確定し、ロット在庫から出庫しました。' : '注文を下書き保存しました。');
    }

    public function update(OrderRequest $request, Order $order, ConfirmOrder $confirmOrder): RedirectResponse
    {
        DB::transaction(function () use ($request, $order, $confirmOrder): void {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => '確定済みの注文は変更できません。']);
            }
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            $current->fill($request->safe()->only(['customer_id', 'ordered_on', 'notes']));
            $current->lock_version = $current->lock_version + 1;
            $current->save();
            $this->syncLines($request, $current);
            if ($request->validated('action') === 'confirm') {
                $confirmOrder->handle($current, $request->user());
            }
        }, 3);

        return to_route('admin.orders.edit', $order)->with('status', $request->validated('action') === 'confirm' ? '注文を確定し、ロット在庫から出庫しました。' : '注文を下書き保存しました。');
    }

    public function updateStatus(Request $request, Order $order, UpdateOrderStatus $updateOrderStatus): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['preparing', 'shipped', 'completed'])]]);
        $updateOrderStatus->handle($order, $data['status']);

        return back()->with('status', '注文状態を変更しました。');
    }

    public function cancel(Request $request, Order $order, CancelOrder $cancelOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $cancelOrder->handle($order, $request->user(), trim($data['reason']));

        return back()->with('status', '注文をキャンセルし、引き当て済み在庫を元のロットへ戻しました。');
    }

    private function customers(?Order $order = null): Collection
    {
        return User::where(function ($query) use ($order): void {
            $query->where('role', 'customer');
            if ($order) {
                $query->orWhereKey($order->customer_id);
            }
        })->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function products(?Order $order = null): Collection
    {
        $productIds = $order?->lines->pluck('product_id')->all() ?? [];

        return Product::where(function ($query) use ($productIds): void {
            $query->where('registration_status', 'confirmed');
            if ($productIds !== []) {
                $query->orWhereIn('id', $productIds);
            }
        })->with(['unit:id,name,code,quantity_scale', 'bundleComponents.componentProduct.inventoryLots'])->orderBy('name')->get(['id', 'sku', 'name', 'kind', 'unit_id', 'default_sale_price'])->each(fn (Product $product) => $product->setAttribute('available_quantity', $product->availableQuantity()));
    }

    private function syncLines(OrderRequest $request, Order $order): void
    {
        $existing = $order->lines()->lockForUpdate()->get()->keyBy('id');
        $submittedIds = collect($request->validated('lines'))->pluck('id')->filter()->map(fn ($id): int => (int) $id);
        if ($submittedIds->contains(fn (int $id): bool => ! $existing->has($id))) {
            throw ValidationException::withMessages(['lines' => '別の注文に属する明細は編集できません。']);
        }

        $products = Product::with('unit:id,name')->whereIn('id', collect($request->validated('lines'))->pluck('product_id'))->get()->keyBy('id');
        $order->lines()->whereNotIn('id', $submittedIds)->delete();
        $order->lines()->increment('line_number', 1000);
        $subtotal = '0';
        foreach ($request->validated('lines') as $index => $input) {
            $id = isset($input['id']) ? (int) $input['id'] : null;
            $line = $id ? $existing->get($id) : new OrderLine;
            $product = $products->get((int) $input['product_id']);
            $amount = bcmul((string) $input['quantity'], (string) $input['unit_sale_price'], 4);
            $line->fill([
                'line_number' => $index + 1,
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'unit_name' => $product->unit->name,
                'quantity' => $input['quantity'],
                'unit_sale_price' => $input['unit_sale_price'],
                'line_amount' => $amount,
            ]);
            $order->lines()->save($line);
            $subtotal = bcadd($subtotal, $amount, 4);
        }
        $order->subtotal = $subtotal;
        $order->total = $subtotal;
        $order->save();
    }
}
