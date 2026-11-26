<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\AdminProductStock;
use App\Actions\Products\ProductSchedule;
use App\Actions\Products\ValidateProductVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'product_group_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')],
            'ungrouped' => ['nullable', 'boolean'],
            'availability' => ['nullable', Rule::in(['available', 'sold_out', 'backorder'])],
            'attribute_definition_id' => ['nullable', 'integer', Rule::exists('product_attribute_definitions', 'id')],
            'attribute_value' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'uncategorized' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'registration_status' => ['nullable', Rule::in(['provisional', 'confirmed'])],
            'publication_status' => ['nullable', Rule::in(['private', 'public'])],
            'kind' => ['nullable', Rule::in(['single', 'bundle'])],
        ]);
        $query = AdminProductStock::query()->with(['images', 'unit:id,name', 'categories:id,name', 'group:id,name', 'attributeValues.definition:id,name']);
        if ($filters['product_group_id'] ?? null) {
            $query->where('product_group_id', $filters['product_group_id']);
        }
        if ($filters['ungrouped'] ?? false) {
            $query->whereNull('products.product_group_id');
        }
        if (($filters['availability'] ?? null) === 'available') {
            $query->whereRaw(AdminProductStock::expression().' > 0');
        } elseif (in_array($filters['availability'] ?? null, ['sold_out', 'backorder'], true)) {
            $query->whereRaw(AdminProductStock::expression().' <= 0');
            if ($filters['availability'] === 'backorder') {
                $query->where('products.shortage_policy', 'backorder');
            }
        }
        if ($filters['attribute_definition_id'] ?? null) {
            $query->whereHas('attributeValues', function ($values) use ($filters): void {
                $values->where('product_attribute_definition_id', $filters['attribute_definition_id']);
                if (($filters['attribute_value'] ?? '') !== '') {
                    $values->where('value', 'like', '%'.$filters['attribute_value'].'%');
                }
            });
        }
        if ($filters['category_id'] ?? null) {
            $ids = Category::subtreeIds((int) $filters['category_id']);
            $query->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $ids));
        }
        if ($filters['uncategorized'] ?? false) {
            $query->doesntHave('categories');
        }
        $term = $filters['q'] ?? '';
        if ($term !== '' && $term !== null) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'));
        }
        foreach (['registration_status', 'publication_status', 'kind'] as $key) {
            if ($filters[$key] ?? null) {
                $query->where($key, $filters[$key]);
            }
        }

        return Inertia::render('Admin/Products/Index', [
            'products' => $query->latest('products.id')->paginate(20)->withQueryString()
                ->through(function ($product) {
                    if ($product->group) {
                        $product->group->setAttribute('show_url', route('admin.product-groups.show', $product->group, false));
                    }

                    return $product;
                }),
            'indexUrl' => route('admin.products.index', [], false),
            'filters' => $filters,
            'categories' => Category::treeOptions(),
            'groups' => $this->groups(),
            'attributeDefinitions' => ProductAttributeDefinition::where('status', 'active')->with('group:id,name')->orderBy('name')->get(['id', 'product_group_id', 'name', 'input_type', 'options']),
            'counts' => ['all' => Product::count(), 'provisional' => Product::where('registration_status', 'provisional')->count(), 'confirmed' => Product::where('registration_status', 'confirmed')->count()],
        ]);
    }

    public function create(Request $request): Response
    {
        $data = $request->validate([
            'product_group_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')->where('status', 'active')],
            'from_product_group_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')->where('status', 'active')],
        ]);
        $groupId = $data['product_group_id'] ?? null;
        abort_if(isset($data['from_product_group_id']) && (int) $data['from_product_group_id'] !== (int) $groupId, 422);

        return Inertia::render('Admin/Products/Form', ['scheduleValues' => ProductSchedule::formValues(null), 'product' => null, 'selectedGroupId' => $groupId, 'returnGroup' => isset($data['from_product_group_id']) ? $this->returnGroup((int) $data['from_product_group_id']) : null, 'saveUrl' => route('admin.products.store', [], false), 'componentProducts' => $this->componentProducts(), 'groups' => $this->groups(), 'categories' => Category::treeOptions(), 'units' => Unit::where('status', 'active')->orderBy('id')->get(['id', 'name', 'code']), 'taxRates' => $this->taxRates()]);
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless(in_array($product->registration_status, ['provisional', 'confirmed'], true), 404);

        $data = $request->validate(['from_product_group_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')]]);
        abort_if(isset($data['from_product_group_id']) && (int) $data['from_product_group_id'] !== $product->product_group_id, 404);

        return Inertia::render('Admin/Products/Form', ['scheduleValues' => ProductSchedule::formValues($product), 'returnGroup' => isset($data['from_product_group_id']) ? $this->returnGroup((int) $data['from_product_group_id']) : null, 'saveUrl' => route('admin.products.update', $product, false), 'componentProducts' => $this->componentProducts($product), 'product' => $product->load(['images', 'categories:id,name', 'attributeValues.definition', 'group', 'bundleComponents.componentProduct.unit']), 'imagesUrl' => route('admin.products.images.store', $product, false), 'groups' => $this->groups(), 'categories' => Category::treeOptions(), 'units' => Unit::where('status', 'active')->orderBy('id')->get(['id', 'name', 'code']), 'taxRates' => $this->taxRates($product)]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            app(ValidateProductVariant::class)->lockGroup($request->integer('product_group_id') ?: null);
            $product = new Product($request->safe()->except(['lock_version', 'category_ids', 'attribute_values', 'bundle_components', 'images_present', 'from_product_group_id', ...array_keys(ProductSchedule::FIELDS)]));
            $product->fill(ProductSchedule::storageValues($request->validated()));
            $product->sku = $product->sku ?: 'P-'.Str::ulid();
            $product->created_by = $request->user()->id;
            if ($product->registration_status === 'confirmed') {
                $product->confirmed_by = $request->user()->id;
                $product->confirmed_at = now();
            }
            $this->persist($product);
            $this->syncCategories($request, $product);
            $this->syncAttributeValues($request, $product, null);
            app(ValidateProductVariant::class)->validate($product);
            $this->syncBundleComponents($request, $product);

            return $product;
        }, 3);

        if ($request->validated('from_product_group_id')) {
            return to_route('admin.product-groups.show', $request->validated('from_product_group_id'))->with('status', '販売商品を登録しました。');
        }

        return to_route('admin.products.edit', $product)->with('status', $product->registration_status === 'confirmed' ? '販売商品を本登録しました。' : '販売商品を仮登録しました。');
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product): void {
            $current = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            if (! in_array($current->registration_status, ['provisional', 'confirmed'], true)) {
                throw ValidationException::withMessages(['registration_status' => 'この販売商品は編集できません。']);
            }
            if ($current->registration_status === 'confirmed') {
                if ($request->validated('registration_status') !== 'confirmed') {
                    throw ValidationException::withMessages(['registration_status' => '本登録の販売商品を仮登録に戻すことはできません。']);
                }
                if ($request->validated('kind') !== $current->kind) {
                    throw ValidationException::withMessages(['kind' => '本登録後は単品とセット商品を切り替えられません。']);
                }
                if ((int) $request->validated('unit_id') !== $current->unit_id) {
                    throw ValidationException::withMessages(['unit_id' => '本登録後の単位は変更できません。']);
                }
            }
            app(ValidateProductVariant::class)->lockGroup($request->integer('product_group_id') ?: null, $current->product_group_id);
            $sku = $current->sku;
            $previousGroupId = $current->product_group_id;
            $current->fill($request->safe()->except(['lock_version', 'category_ids', 'attribute_values', 'bundle_components', 'images_present', 'from_product_group_id', ...array_keys(ProductSchedule::FIELDS)]));
            $current->fill(ProductSchedule::storageValues($request->validated()));
            $current->sku = $current->sku ?: $sku;
            if ($current->registration_status === 'confirmed' && ! $current->confirmed_at) {
                $current->confirmed_by = $request->user()->id;
                $current->confirmed_at = now();
            }
            $current->lock_version++;
            $this->persist($current);
            $this->syncCategories($request, $current);
            $this->syncAttributeValues($request, $current, $previousGroupId);
            app(ValidateProductVariant::class)->validate($current);
            $this->syncBundleComponents($request, $current);
        }, 3);

        if ($request->validated('from_product_group_id')) {
            return to_route('admin.product-groups.show', $request->validated('from_product_group_id'))->with('status', '販売商品を保存しました。');
        }

        return to_route('admin.products.edit', $product)->with('status', '販売商品を保存しました。');
    }

    private function returnGroup(int $id): array
    {
        $group = ProductGroup::findOrFail($id);

        return ['id' => $group->id, 'name' => $group->name, 'url' => route('admin.product-groups.show', $group, false)];
    }

    private function componentProducts(?Product $product = null): mixed
    {
        return Product::where('kind', 'single')->where('registration_status', 'confirmed')
            ->when($product, fn ($query) => $query->where('id', '!=', $product->id))
            ->with('unit:id,name,quantity_scale')->orderBy('name')->get(['id', 'sku', 'name', 'unit_id']);
    }

    private function syncBundleComponents(ProductRequest $request, Product $product): void
    {
        if ($product->kind !== 'bundle') {
            $product->bundleComponents()->delete();

            return;
        }
        $product->bundleComponents()->delete();
        foreach ($request->validated('bundle_components') as $index => $component) {
            $product->bundleComponents()->create([
                'component_product_id' => $component['component_product_id'],
                'quantity' => $component['quantity'],
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function taxRates(?Product $product = null): mixed
    {
        return TaxRate::where('status', 'active')
            ->when($product?->tax_rate_id, fn ($query, $id) => $query->orWhere('id', $id))
            ->orderBy('rate')->get(['id', 'code', 'name', 'rate']);
    }

    private function groups(): mixed
    {
        return ProductGroup::with(['definitions' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')->get();
    }

    private function syncAttributeValues(ProductRequest $request, Product $product, ?int $previousGroupId): void
    {
        if (! $request->has('attribute_values') && $previousGroupId === $product->product_group_id) {
            return;
        }
        if ($previousGroupId !== $product->product_group_id || ! $product->product_group_id) {
            $product->attributeValues()->delete();
        }
        if (! $product->product_group_id) {
            return;
        }
        $definitions = ProductAttributeDefinition::where('product_group_id', $product->product_group_id)
            ->where('status', 'active')->lockForUpdate()->get()->keyBy('id');
        $values = $request->validated('attribute_values', []);
        foreach ($definitions as $definition) {
            $value = trim((string) ($values[$definition->id] ?? ''));
            if ($value === '') {
                $product->attributeValues()->where('product_attribute_definition_id', $definition->id)->delete();

                continue;
            }
            $product->attributeValues()->updateOrCreate(
                ['product_attribute_definition_id' => $definition->id],
                ['value' => $value],
            );
        }
    }

    private function syncCategories(ProductRequest $request, Product $product): void
    {
        if (! $request->has('category_ids')) {
            return;
        }
        $ids = array_map('intval', $request->validated('category_ids'));
        $existing = $product->categories()->pluck('categories.id')->all();
        $nodes = Category::orderBy('id')->lockForUpdate()->get();
        $allowed = collect(Category::treeOptions($nodes))->where('effective_active', true)->pluck('id')->all();
        foreach ($ids as $id) {
            if (! in_array($id, $allowed, true) && ! in_array($id, $existing, true)) {
                throw ValidationException::withMessages(['category_ids' => '無効な商品分類（親が無効の場合も含む）は新しく設定できません。選び直してください。']);
            }
        }
        $product->categories()->sync($ids);
    }

    private function persist(Product $product): void
    {
        try {
            $product->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'この商品コードはすでに使われています。']);
        }
    }
}
