<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Shipments\CancelShipment;
use App\Actions\Shipments\ConfirmShipment;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShipmentRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShipmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['draft', 'shipped', 'cancelled'])],
            'scheduled_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $query = Shipment::query()->with('order.customer:id,name,email')->withCount('lines');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($shipment) => $shipment->where('shipment_number', 'like', '%'.$term.'%')
                ->orWhere('tracking_number', 'like', '%'.$term.'%')
                ->orWhere('carrier', 'like', '%'.$term.'%')
                ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))));
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['scheduled_month'] ?? null) {
            $first = $filters['scheduled_month'].'-01';
            $query->whereBetween('scheduled_on', [$first, date('Y-m-t', strtotime($first))]);
        }

        return Inertia::render('Admin/Shipments/Index', [
            'shipments' => $query->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'counts' => [
                'all' => Shipment::count(),
                'draft' => Shipment::where('status', 'draft')->count(),
                'shipped' => Shipment::where('status', 'shipped')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $selectedOrderId = $request->validate(['order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')]])['order_id'] ?? null;

        return Inertia::render('Admin/Shipments/Form', [
            'shipment' => null,
            'orders' => $this->orders(),
            'selectedOrderId' => $selectedOrderId ? (int) $selectedOrderId : null,
        ]);
    }

    public function store(ShipmentRequest $request, ConfirmShipment $confirmShipment): RedirectResponse
    {
        $shipment = DB::transaction(function () use ($request, $confirmShipment): Shipment {
            $shipment = new Shipment($request->safe()->only(['order_id', 'carrier', 'tracking_number', 'scheduled_on', 'shipped_on', 'notes']));
            $shipment->shipment_number = 'S-'.Str::ulid();
            $shipment->status = 'draft';
            $shipment->created_by = $request->user()->id;
            $shipment->save();
            $this->syncLines($request, $shipment);

            $order = Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'confirmed') {
                $order->status = 'preparing';
                $order->lock_version++;
                $order->save();
            }
            if ($request->validated('action') === 'ship') {
                $confirmShipment->handle($shipment, $request->user());
            }

            return $shipment;
        }, 3);

        return to_route('admin.shipments.edit', $shipment)->with('status', $shipment->fresh()->status === 'shipped' ? '発送を確定しました。' : '発送を下書き保存しました。');
    }

    public function edit(Shipment $shipment): Response
    {
        $shipment->load(['order.customer:id,name,email', 'lines.orderLine']);

        return Inertia::render('Admin/Shipments/Form', [
            'shipment' => $shipment,
            'orders' => $this->orders($shipment),
            'selectedOrderId' => $shipment->order_id,
        ]);
    }

    public function update(ShipmentRequest $request, Shipment $shipment, ConfirmShipment $confirmShipment): RedirectResponse
    {
        DB::transaction(function () use ($request, $shipment, $confirmShipment): void {
            $current = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => '確定または取消済みの発送は変更できません。']);
            }
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            if ($current->order_id !== (int) $request->validated('order_id')) {
                throw ValidationException::withMessages(['order_id' => '保存後に注文を変更することはできません。']);
            }
            $current->fill($request->safe()->only(['carrier', 'tracking_number', 'scheduled_on', 'shipped_on', 'notes']));
            $current->lock_version++;
            $current->save();
            $this->syncLines($request, $current);
            if ($request->validated('action') === 'ship') {
                $confirmShipment->handle($current, $request->user());
            }
        }, 3);

        return back()->with('status', $request->validated('action') === 'ship' ? '発送を確定しました。' : '発送を保存しました。');
    }

    public function cancel(Request $request, Shipment $shipment, CancelShipment $cancelShipment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $cancelShipment->handle($shipment, $request->user(), trim($data['reason']));

        return back()->with('status', '発送の下書きを取り消しました。');
    }

    private function syncLines(ShipmentRequest $request, Shipment $shipment): void
    {
        $inputs = $request->validated('lines');
        $orderLines = OrderLine::where('order_id', $shipment->order_id)
            ->whereIn('id', collect($inputs)->pluck('order_line_id'))
            ->lockForUpdate()->get()->keyBy('id');
        foreach ($inputs as $index => $input) {
            $orderLine = $orderLines->get((int) $input['order_line_id']);
            if (! $orderLine) {
                throw ValidationException::withMessages(["lines.$index.order_line_id" => 'この注文の商品を選択してください。']);
            }
            $reserved = $orderLine->shipmentLines()
                ->where('shipment_id', '!=', $shipment->id)
                ->whereHas('shipment', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->sum('quantity');
            $remaining = bcsub((string) $orderLine->quantity, (string) $reserved, 4);
            if (bccomp((string) $input['quantity'], $remaining, 4) === 1) {
                throw ValidationException::withMessages(["lines.$index.quantity" => "未発送数量（{$remaining} {$orderLine->unit_name}）以内で入力してください。"]);
            }
        }

        $shipment->lines()->delete();
        foreach ($inputs as $input) {
            $shipment->lines()->create(['order_line_id' => $input['order_line_id'], 'quantity' => $input['quantity']]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function orders(?Shipment $currentShipment = null): array
    {
        return Order::where(function ($query) use ($currentShipment): void {
            $query->whereIn('status', ['confirmed', 'preparing']);
            if ($currentShipment) {
                $query->orWhereKey($currentShipment->order_id);
            }
        })->with(['customer:id,name,email', 'lines.product.unit:id,name,quantity_scale', 'lines.shipmentLines.shipment:id,status'])
            ->latest('ordered_on')->latest('id')->get()->map(function (Order $order) use ($currentShipment): array {
                $lines = $order->lines->map(function ($line) use ($currentShipment): ?array {
                    $reserved = $line->shipmentLines
                        ->filter(fn ($shipmentLine): bool => $shipmentLine->shipment->status !== 'cancelled' && $shipmentLine->shipment_id !== $currentShipment?->id)
                        ->sum('quantity');
                    $remaining = bcsub((string) $line->quantity, (string) $reserved, 4);
                    $currentQuantity = $currentShipment?->lines->firstWhere('order_line_id', $line->id)?->quantity;
                    if (bccomp($remaining, '0', 4) !== 1 && $currentQuantity === null) {
                        return null;
                    }

                    return [
                        'id' => $line->id,
                        'product_sku' => $line->product_sku,
                        'product_name' => $line->product_name,
                        'unit_name' => $line->unit_name,
                        'quantity_scale' => $line->product->unit->quantity_scale,
                        'ordered_quantity' => $line->quantity,
                        'remaining_quantity' => $remaining,
                    ];
                })->filter()->values()->all();

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'ordered_on' => $order->ordered_on->format('Y-m-d'),
                    'status' => $order->status,
                    'customer' => $order->customer->only(['id', 'name', 'email']),
                    'lines' => $lines,
                ];
            })->filter(fn (array $order): bool => $order['lines'] !== [])->values()->all();
    }
}
