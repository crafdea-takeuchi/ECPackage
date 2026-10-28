<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(Request $request): Response
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
        $products = $query->with(['images', 'unit:id,name,code,quantity_scale', 'categories:id,name', 'bundleComponents.componentProduct.inventoryLots', 'bundleComponents.componentProduct.unit:id,name,quantity_scale'])->orderBy('name')->paginate(12)->withQueryString();
        $products->getCollection()->each(fn (Product $product) => $product->setAttribute('available_quantity', $product->availableQuantity()));

        return Inertia::render('Shop/Index', ['products' => $products, 'filters' => $filters, 'categories' => Category::where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])]);
    }
}
