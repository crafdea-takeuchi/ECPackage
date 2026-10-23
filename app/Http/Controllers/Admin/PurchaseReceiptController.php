<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\AllocateBackorders;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReceiptRequest;
use App\Models\InventoryLot;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\StockMovement;
use App\Models\Supplier;
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

class PurchaseReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'posted'])],
            'received_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $query = PurchaseReceipt::query()->with('supplier:id,code,name')->withCount('lines')->withSum('lines', 'purchase_amount');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($receipt) => $receipt->where('receipt_number', 'like', '%'.$term.'%')->orWhereHas('lines', fn ($line) => $line->where('lot_number', 'like', '%'.$term.'%')));
        }
        if ($filters['supplier_id'] ?? null) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['received_month'] ?? null) {
            $query->whereBetween('received_on', [$filters['received_month'].'-01', date('Y-m-t', strtotime($filters['received_month'].'-01'))]);
        }

        return Inertia::render('Admin/PurchaseReceipts/Index', [
            'receipts' => $query->latest('received_on')->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name', 'status']),
            'counts' => ['all' => PurchaseReceipt::count(), 'draft' => PurchaseReceipt::where('status', 'draft')->count(), 'posted' => PurchaseReceipt::where('status', 'posted')->count()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/PurchaseReceipts/Form', ['receipt' => null, 'suppliers' => $this->suppliers()]);
    }

    public function edit(PurchaseReceipt $purchaseReceipt): Response
    {
        $purchaseReceipt->load(['supplier:id,code,name,status', 'lines.product.unit:id,name,code,quantity_scale', 'lines.purchaseSetting']);

        return Inertia::render('Admin/PurchaseReceipts/Form', ['receipt' => $purchaseReceipt, 'suppliers' => $this->suppliers($purchaseReceipt)]);
    }

    public function store(PurchaseReceiptRequest $request, AllocateBackorders $allocateBackorders): RedirectResponse
    {
        $receipt = DB::transaction(function () use ($request, $allocateBackorders): PurchaseReceipt {
            $receipt = new PurchaseReceipt($request->safe()->only(['supplier_id', 'received_on', 'notes']));
            $receipt->receipt_number = 'R-'.Str::ulid();
            $receipt->status = 'draft';
            $receipt->created_by = $request->user()->id;
            $receipt->save();
            $this->syncLines($request, $receipt);
            if ($request->validated('action') === 'post') {
                $this->postReceipt($receipt, $request->user(), $allocateBackorders);
            }

            return $receipt;
        }, 3);

        return to_route('admin.purchase-receipts.edit', $receipt)->with('status', $receipt->status === 'posted' ? '入庫を確定し、ロット在庫へ反映しました。' : '入庫を下書き保存しました。');
    }

    public function update(PurchaseReceiptRequest $request, PurchaseReceipt $purchaseReceipt, AllocateBackorders $allocateBackorders): RedirectResponse
    {
        DB::transaction(function () use ($request, $purchaseReceipt, $allocateBackorders): void {
            $current = PurchaseReceipt::whereKey($purchaseReceipt->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => '確定済みの入庫は変更できません。']);
            }
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            $current->fill($request->safe()->only(['supplier_id', 'received_on', 'notes']));
            $current->lock_version = $current->lock_version + 1;
            $current->save();
            $this->syncLines($request, $current);
            if ($request->validated('action') === 'post') {
                $this->postReceipt($current, $request->user(), $allocateBackorders);
            }
        }, 3);

        return to_route('admin.purchase-receipts.edit', $purchaseReceipt)->with('status', $request->validated('action') === 'post' ? '入庫を確定し、ロット在庫へ反映しました。' : '入庫を下書き保存しました。');
    }

    private function suppliers(?PurchaseReceipt $receipt = null): Collection
    {
        $settingIds = $receipt?->lines->pluck('product_supplier_id')->all() ?? [];

        return Supplier::where(function ($query) use ($receipt): void {
            $query->where('status', 'active');
            if ($receipt) {
                $query->orWhereKey($receipt->supplier_id);
            }
        })->with(['purchaseSettings' => function ($settings) use ($settingIds): void {
            $settings->where(function ($query) use ($settingIds): void {
                $query->where('status', 'active');
                if ($settingIds !== []) {
                    $query->orWhereIn('id', $settingIds);
                }
            })->with('product.unit:id,name,code,quantity_scale');
        }])->orderBy('name')->get();
    }

    private function syncLines(PurchaseReceiptRequest $request, PurchaseReceipt $receipt): void
    {
        $existing = $receipt->lines()->lockForUpdate()->get()->keyBy('id');
        $submittedIds = collect($request->validated('lines'))->pluck('id')->filter()->map(fn ($id): int => (int) $id);
        if ($submittedIds->contains(fn (int $id): bool => ! $existing->has($id))) {
            throw ValidationException::withMessages(['lines' => '別の入庫に属する明細は編集できません。']);
        }

        $receipt->lines()->whereNotIn('id', $submittedIds)->delete();
        $receipt->lines()->increment('line_number', 1000);
        foreach ($request->validated('lines') as $index => $input) {
            $id = isset($input['id']) ? (int) $input['id'] : null;
            $line = $id ? $existing->get($id) : new PurchaseReceiptLine;
            $line->fill([
                ...$input,
                'line_number' => $index + 1,
                'purchase_amount' => bcmul((string) $input['quantity'], (string) $input['unit_purchase_price'], 4),
            ]);
            $receipt->lines()->save($line);
        }
    }

    private function postReceipt(PurchaseReceipt $receipt, User $user, AllocateBackorders $allocateBackorders): void
    {
        $lines = $receipt->lines()->lockForUpdate()->get();
        $userId = $user->id;
        foreach ($lines as $line) {
            if (InventoryLot::where('purchase_receipt_line_id', $line->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'この入庫はすでに在庫へ反映されています。']);
            }
            $lot = InventoryLot::create([
                'product_id' => $line->product_id,
                'supplier_id' => $receipt->supplier_id,
                'purchase_receipt_line_id' => $line->id,
                'lot_number' => $line->lot_number,
                'received_on' => $receipt->received_on,
                'manufactured_on' => $line->manufactured_on,
                'expires_on' => $line->expires_on,
                'initial_quantity' => $line->quantity,
                'current_quantity' => $line->quantity,
                'unit_purchase_price' => $line->unit_purchase_price,
                'status' => 'active',
            ]);
            StockMovement::create([
                'product_id' => $line->product_id,
                'inventory_lot_id' => $lot->id,
                'movement_type' => 'inbound',
                'occurred_on' => $receipt->received_on,
                'quantity_delta' => $line->quantity,
                'unit_cost' => $line->unit_purchase_price,
                'source_type' => 'purchase_receipt_line',
                'source_id' => $line->id,
                'notes' => '仕入入庫 '.$receipt->receipt_number,
                'created_by' => $userId,
            ]);
        }
        $receipt->status = 'posted';
        $receipt->posted_by = $userId;
        $receipt->posted_at = now();
        $receipt->save();
        $allocateBackorders->handle($lines->pluck('product_id'), $user);
    }
}
