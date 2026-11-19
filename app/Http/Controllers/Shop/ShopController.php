<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Orders\CalculateTaxes;
use App\Actions\Products\ListShopProductGroups;
use App\Actions\Products\ProductSchedule;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(Request $request, ListShopProductGroups $listProducts): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'availability' => ['nullable', Rule::in(['available', 'sold_out', 'backorder'])],
            'attribute' => ['nullable', 'string', 'max:150'],
        ]);

        return Inertia::render('Shop/Index', [
            'products' => $listProducts->handle($filters),
            'filters' => $filters,
            'categories' => Category::where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Product $product, CalculateTaxes $calculateTaxes): Response
    {
        abort_unless(ProductSchedule::visible($product), 404);

        $product->load($this->detailRelations());
        $this->addShopAttributes($product, $calculateTaxes);
        $this->sortAttributeValues($product);

        $variants = collect();
        if ($product->product_group_id !== null) {
            $variants = Product::query()
                ->where('product_group_id', $product->product_group_id)
                ->publiclyVisible()
                ->with($this->detailRelations())
                ->orderBy('name')->orderBy('id')
                ->get()
                ->sortByDesc(fn (Product $variant): bool => $variant->is($product))
                ->values()
                ->each(function (Product $variant) use ($calculateTaxes): void {
                    $this->addShopAttributes($variant, $calculateTaxes);
                    $this->sortAttributeValues($variant);
                });
        }

        return Inertia::render('Shop/Show', [
            'product' => $product,
            'variants' => $variants,
        ]);
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'images',
            'inventoryLots',
            'unit:id,name,code,quantity_scale',
            'taxRate:id,name,rate',
            'categories:id,name',
            'group:id,name,description',
            'group.images',
            'attributeValues.definition:id,name,sort_order,status',
            'bundleComponents.componentProduct.images',
            'bundleComponents.componentProduct.inventoryLots',
            'bundleComponents.componentProduct.unit:id,name,quantity_scale',
        ];
    }

    private function addShopAttributes(Product $product, CalculateTaxes $calculateTaxes): Product
    {
        $quantity = $product->kind !== 'bundle' && $product->relationLoaded('inventoryLots')
            ? $product->inventoryLots->where('current_quantity', '>', 0)->sum('current_quantity')
            : $product->availableQuantity();
        $product->unsetRelation('inventoryLots');
        if ($product->images->isEmpty() && $product->group?->images->isNotEmpty()) {
            $product->setRelation('images', $product->group->images);
        }

        return $product
            ->setAttribute('available_quantity', $quantity)
            ->setAttribute('price_including_tax', $calculateTaxes->handle([[
                'tax_rate_name' => $product->taxRate?->name,
                'tax_rate' => $product->taxRate?->rate ?? '0',
                'amount' => $product->default_sale_price,
            ]])['total']);
    }

    private function sortAttributeValues(Product $product): void
    {
        $product->setRelation(
            'attributeValues',
            $product->attributeValues
                ->sortBy(fn ($value) => [$value->definition?->sort_order ?? PHP_INT_MAX, $value->id])
                ->values(),
        );
    }
}
