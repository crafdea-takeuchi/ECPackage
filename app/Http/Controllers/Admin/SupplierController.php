<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $query = Supplier::query()
            ->withCount([
                'purchaseSettings as active_purchase_settings_count' => fn ($settings) => $settings->where('status', 'active'),
                'purchaseSettings as primary_purchase_settings_count' => fn ($settings) => $settings->where('status', 'active')->where('is_primary', true),
            ]);
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($supplier) => $supplier
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('code', 'like', '%'.$term.'%')
                ->orWhere('contact_name', 'like', '%'.$term.'%'));
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('Admin/Suppliers/Index', [
            'suppliers' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'counts' => ['all' => Supplier::count(), 'active' => Supplier::where('status', 'active')->count()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Suppliers/Form', ['supplier' => null, 'products' => $this->products()]);
    }

    public function edit(Supplier $supplier): Response
    {
        $supplier->load(['purchaseSettings' => fn ($settings) => $settings->with('product:id,sku,name,registration_status')->orderByDesc('status')->orderBy('id')]);

        return Inertia::render('Admin/Suppliers/Form', ['supplier' => $supplier, 'products' => $this->products()]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = new Supplier($request->safe()->only(['code', 'name', 'contact_name', 'email', 'phone', 'postal_code', 'address', 'notes', 'status']));
            $supplier->code = $supplier->code ?: 'S-'.Str::ulid();
            $this->persist($supplier);
            $this->syncPurchaseSettings($request, $supplier);

            return $supplier;
        }, 3);

        return to_route('admin.suppliers.edit', $supplier)->with('status', '仕入先を作成しました。');
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        DB::transaction(function () use ($request, $supplier): void {
            $current = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            $current->fill($request->safe()->only(['code', 'name', 'contact_name', 'email', 'phone', 'postal_code', 'address', 'notes', 'status']));
            $current->code = $current->code ?: 'S-'.Str::ulid();
            $current->lock_version++;
            $this->persist($current);
            $this->syncPurchaseSettings($request, $current);
        }, 3);

        return to_route('admin.suppliers.edit', $supplier)->with('status', '仕入先を保存しました。');
    }

    private function products(): Collection
    {
        return Product::whereIn('registration_status', ['provisional', 'confirmed'])
            ->where('kind', 'single')
            ->orderBy('name')->get(['id', 'sku', 'name', 'registration_status']);
    }

    private function syncPurchaseSettings(SupplierRequest $request, Supplier $supplier): void
    {
        $existing = $supplier->purchaseSettings()->lockForUpdate()->get()->keyBy('product_id');
        $submittedProductIds = [];

        foreach ($request->validated('purchase_settings') as $input) {
            $productId = (int) $input['product_id'];
            $submittedProductIds[] = $productId;
            $input['is_primary'] = $input['status'] === 'active' && (bool) $input['is_primary'];

            if ($input['is_primary']) {
                Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                ProductSupplier::where('product_id', $productId)
                    ->where('supplier_id', '!=', $supplier->id)
                    ->update(['is_primary' => false]);
            }

            $setting = $existing->get($productId) ?? new ProductSupplier;
            $setting->fill($input);
            $supplier->purchaseSettings()->save($setting);
        }

        $supplier->purchaseSettings()
            ->whereNotIn('product_id', $submittedProductIds)
            ->update(['status' => 'inactive', 'is_primary' => false]);

        if ($supplier->status === 'inactive') {
            $supplier->purchaseSettings()->update(['is_primary' => false]);
        }
    }

    private function persist(Supplier $supplier): void
    {
        try {
            $supplier->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'この仕入先コードはすでに使われています。']);
        }
    }
}
