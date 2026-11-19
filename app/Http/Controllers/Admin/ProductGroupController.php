<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\AdminProductStock;
use App\Actions\Products\SyncProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductGroupRequest;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ProductGroups/Index', [
            'groups' => ProductGroup::with(['definitions', 'images'])->withCount([
                'products', 'definitions',
                'products as public_products_count' => fn ($query) => $query->where('registration_status', 'confirmed')->where('publication_status', 'public'),
                'products as private_products_count' => fn ($query) => $query->where('publication_status', 'private'),
                'products as stocked_products_count' => fn ($query) => $query->whereIn('products.id', AdminProductStock::query()->whereRaw(AdminProductStock::expression().' > 0')->select('products.id')),
            ])->withMin('products', 'default_sale_price')->withMax('products', 'default_sale_price')->orderBy('name')->get()
                ->each(fn ($group) => $group->setAttribute('show_url', route('admin.product-groups.show', $group, false))->setAttribute('edit_url', route('admin.product-groups.edit', $group, false))),
        ]);
    }

    public function show(ProductGroup $productGroup): Response
    {
        $products = AdminProductStock::query()->where('products.product_group_id', $productGroup->id)
            ->with(['images', 'unit', 'taxRate', 'attributeValues.definition'])
            ->orderBy('products.id')->paginate(20)->withQueryString();
        $products->getCollection()->each(function ($product) use ($productGroup): void {
            $product->setAttribute('edit_url', in_array($product->registration_status, ['provisional', 'confirmed'], true)
                ? route('admin.products.edit', ['product' => $product, 'from_product_group_id' => $productGroup->id], false) : null);
        });

        return Inertia::render('Admin/ProductGroups/Show', [
            'productGroup' => $productGroup->load(['definitions', 'images'])->loadCount('products'),
            'products' => $products,
            'indexUrl' => route('admin.product-groups.index', [], false),
            'editUrl' => route('admin.product-groups.edit', $productGroup, false),
            'createProductUrl' => $productGroup->status === 'active'
                ? route('admin.products.create', ['product_group_id' => $productGroup->id, 'from_product_group_id' => $productGroup->id], false) : null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ProductGroups/Form', ['productGroup' => null]);
    }

    public function edit(ProductGroup $productGroup): Response
    {
        return Inertia::render('Admin/ProductGroups/Form', ['productGroup' => $productGroup->load(['definitions', 'images'])->loadCount('products'), 'showUrl' => route('admin.product-groups.show', $productGroup, false)]);
    }

    public function store(ProductGroupRequest $request, SyncProductImages $images): RedirectResponse
    {
        $group = $images->handle($request->validated('images'), fn () => DB::transaction(function () use ($request, $images): ProductGroup {
            $group = ProductGroup::create($request->safe()->only(['name', 'description', 'status']));
            $this->syncDefinitions($request, $group);
            $images->sync($group, $request->validated('images'));

            return $group;
        }, 3));

        return to_route('admin.product-groups.edit', $group)->with('status', '基本商品を作成しました。');
    }

    public function update(ProductGroupRequest $request, ProductGroup $productGroup, SyncProductImages $images): RedirectResponse
    {
        $images->handle($request->validated('images'), fn () => DB::transaction(function () use ($request, $productGroup, $images): void {
            $current = ProductGroup::whereKey($productGroup->id)->lockForUpdate()->firstOrFail();
            if ($current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            $current->fill($request->safe()->only(['name', 'description', 'status']));
            $current->lock_version++;
            $current->save();
            $this->syncDefinitions($request, $current);
            $images->sync($current, $request->validated('images'));
        }, 3));

        return to_route('admin.product-groups.edit', $productGroup)->with('status', '基本商品を保存しました。');
    }

    private function syncDefinitions(ProductGroupRequest $request, ProductGroup $group): void
    {
        $definitions = $group->definitions()->lockForUpdate()->get()->keyBy('id');
        $submittedIds = [];
        foreach ($request->validated('attributes') as $attribute) {
            $id = isset($attribute['id']) ? (int) $attribute['id'] : null;
            $definition = $id ? $definitions->get($id) : new ProductAttributeDefinition;
            if (! $definition) {
                throw ValidationException::withMessages(['attributes' => '別の基本商品の属性は編集できません。']);
            }
            $codeOwner = $definitions->firstWhere('code', $attribute['code']);
            if ($codeOwner && $codeOwner->id !== $definition->id) {
                throw ValidationException::withMessages(['attributes' => '同じ属性コードがこの基本商品ですでに使われています。']);
            }
            if ($definition->exists && $definition->values()->exists()) {
                if ($definition->input_type !== $attribute['input_type']) {
                    throw ValidationException::withMessages(['attributes' => '商品で使用中の属性は入力形式を変更できません。']);
                }
                if ($definition->input_type === 'select') {
                    $used = $definition->values()->distinct()->pluck('value')->all();
                    if (array_diff($used, $attribute['options']) !== []) {
                        throw ValidationException::withMessages(['attributes' => '商品で使用中の選択肢は削除できません。']);
                    }
                }
            }
            $definition->fill($attribute);
            $group->definitions()->save($definition);
            $submittedIds[] = $definition->id;
        }
        $group->definitions()->whereNotIn('id', $submittedIds)->update(['status' => 'inactive']);
    }
}
