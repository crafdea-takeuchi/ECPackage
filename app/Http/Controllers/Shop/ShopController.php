<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Orders\CalculateTaxes;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(Request $request, CalculateTaxes $calculateTaxes): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')], 'availability' => ['nullable', Rule::in(['available', 'sold_out'])]]);
        $query = Product::query()->where('registration_status', 'confirmed')->where('publication_status', 'public')->whereNotNull('default_sale_price');
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($product) => $product->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'));
        }
        if ($filters['category_id'] ?? null) {
            $query->whereHas('categories', fn ($category) => $category->whereKey($filters['category_id']));
        }
        if ($filters['availability'] ?? null) {
            $matching = (clone $query)->with('bundleComponents.componentProduct.inventoryLots')->get()->filter(function (Product $product) use ($filters): bool {
                $available = bccomp($product->availableQuantity(), '0', 4) === 1;

                return $filters['availability'] === 'available' ? $available : ! $available;
            })->pluck('id');
            $query->whereIn('id', $matching);
        }
        $products = $query->with(['images', 'unit:id,name,code,quantity_scale', 'taxRate:id,name,rate', 'categories:id,name', 'bundleComponents.componentProduct.inventoryLots', 'bundleComponents.componentProduct.unit:id,name,quantity_scale'])->orderBy('name')->paginate(12)->withQueryString();
        $products->getCollection()->each(fn (Product $product) => $this->addShopAttributes($product, $calculateTaxes));

        return Inertia::render('Shop/Index', ['products' => $products, 'filters' => $filters, 'categories' => Category::where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Product $product, CalculateTaxes $calculateTaxes): Response
    {
        abort_unless(
            $product->registration_status === 'confirmed'
            && $product->publication_status === 'public'
            && $product->default_sale_price !== null,
            404,
        );

        $product->load($this->detailRelations());
        $this->addShopAttributes($product, $calculateTaxes);
        $this->sortAttributeValues($product);

        $variants = collect();
        if ($product->product_group_id !== null) {
            $variants = Product::query()
                ->where('product_group_id', $product->product_group_id)
                ->where('registration_status', 'confirmed')
                ->where('publication_status', 'public')
                ->whereNotNull('default_sale_price')
                ->with($this->detailRelations())
                ->orderBy('name')
                ->limit(20)
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
            'unit:id,name,code,quantity_scale',
            'taxRate:id,name,rate',
            'categories:id,name',
            'group:id,name,description',
            'attributeValues.definition:id,name,sort_order,status',
            'bundleComponents.componentProduct.images',
            'bundleComponents.componentProduct.inventoryLots',
            'bundleComponents.componentProduct.unit:id,name,quantity_scale',
        ];
    }

    private function addShopAttributes(Product $product, CalculateTaxes $calculateTaxes): Product
    {
        return $product
            ->setAttribute('available_quantity', $product->availableQuantity())
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
