<?php

namespace App\Actions\Products;

use App\Actions\Orders\CalculateTaxes;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListShopProductGroups
{
    public function __construct(private CalculateTaxes $calculateTaxes) {}

    /** @param array{q?: ?string, category_id?: ?int, availability?: ?string, attribute?: ?string} $filters */
    public function handle(array $filters): LengthAwarePaginator
    {
        $matching = $this->query();
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $matching->where(fn (Builder $query) => $query->where('products.name', 'like', '%'.$term.'%')
                ->orWhere('products.sku', 'like', '%'.$term.'%')
                ->orWhereHas('group', fn (Builder $group) => $group->where('name', 'like', '%'.$term.'%')));
        }
        if ($filters['category_id'] ?? null) {
            $matching->whereHas('categories', fn (Builder $category) => $category->whereKey($filters['category_id']));
        }
        if ($attribute = trim((string) ($filters['attribute'] ?? ''))) {
            $matching->whereHas('attributeValues', fn (Builder $value) => $value->where('value', 'like', '%'.$attribute.'%')
                ->whereHas('definition', fn (Builder $definition) => $definition->where('status', 'active')));
        }
        $stock = "CASE WHEN products.kind = 'bundle' THEN COALESCE(bundle_stock.quantity, 0) ELSE COALESCE(stock.quantity, 0) END";
        if (($filters['availability'] ?? null) === 'available') {
            $matching->whereRaw($stock.' > 0');
        } elseif (($filters['availability'] ?? null) === 'sold_out') {
            $matching->whereRaw($stock.' <= 0');
        } elseif (($filters['availability'] ?? null) === 'backorder') {
            $matching->whereRaw($stock.' <= 0')->where('products.shortage_policy', 'backorder');
        }

        $standalone = 'CASE WHEN products.product_group_id IS NULL THEN products.id ELSE NULL END';
        $page = (clone $matching)->select('products.product_group_id')->selectRaw($standalone.' AS standalone_id')
            ->selectRaw('MIN(products.name) AS sort_name, MIN(products.id) AS sort_id')
            ->groupBy('products.product_group_id')->groupByRaw($standalone)
            ->orderBy('sort_name')->orderBy('sort_id')->paginate(12)->withQueryString();
        if ($page->isEmpty()) {
            return $page;
        }

        $groupIds = $page->getCollection()->pluck('product_group_id')->filter()->all();
        $singleIds = $page->getCollection()->pluck('standalone_id')->filter()->all();
        $members = (clone $matching)->where(fn (Builder $query) => $query->whereIn('products.product_group_id', $groupIds)->orWhereIn('products.id', $singleIds))
            ->with(['group:id,name', 'images', 'taxRate:id,name,rate', 'unit:id,name,code,quantity_scale', 'categories:id,name',
                'attributeValues.definition:id,name,sort_order,status', 'bundleComponents.componentProduct.unit:id,name,quantity_scale'])
            ->orderBy('products.name')->orderBy('products.id')->get()->groupBy(fn (Product $product): string => $this->key($product->product_group_id, $product->id));
        $page->setCollection($page->getCollection()->map(fn (Product $row): array => $this->card($members->get($this->key($row->product_group_id, $row->standalone_id)))));

        return $page;
    }

    private function query(): Builder
    {
        $stock = DB::table('inventory_lots')->select('product_id')->selectRaw('SUM(current_quantity) AS quantity')->where('current_quantity', '>', 0)->groupBy('product_id');
        $bundles = DB::table('product_bundle_components as component')->leftJoinSub(clone $stock, 'component_stock', 'component_stock.product_id', '=', 'component.component_product_id')
            ->select('component.bundle_product_id')->selectRaw('MIN(FLOOR(COALESCE(component_stock.quantity, 0) / component.quantity)) AS quantity')->groupBy('component.bundle_product_id');

        return Product::query()->where('products.registration_status', 'confirmed')->where('products.publication_status', 'public')->whereNotNull('products.default_sale_price')
            ->leftJoinSub($stock, 'stock', 'stock.product_id', '=', 'products.id')
            ->leftJoinSub($bundles, 'bundle_stock', 'bundle_stock.bundle_product_id', '=', 'products.id')
            ->select('products.*')->selectRaw("CASE WHEN products.kind = 'bundle' THEN COALESCE(bundle_stock.quantity, 0) ELSE COALESCE(stock.quantity, 0) END AS available_quantity");
    }

    private function key(?int $groupId, ?int $productId): string
    {
        return $groupId === null ? 'product:'.$productId : 'group:'.$groupId;
    }

    /** @param Collection<int, Product> $members */
    private function card(Collection $members): array
    {
        $first = $members->first();
        $variantPrices = $members->mapWithKeys(fn (Product $product): array => [$product->id => $this->calculateTaxes->handle([[
            'tax_rate_name' => $product->taxRate?->name,
            'tax_rate' => $product->taxRate?->rate ?? '0',
            'amount' => $product->default_sale_price,
        ]])['total']]);
        $prices = $variantPrices->values()->sort(fn (string $a, string $b): int => bccomp($a, $b, 4))->values();
        $hasStock = $members->contains(fn (Product $product): bool => bccomp((string) $product->available_quantity, '0', 4) === 1);
        $backorder = $members->contains(fn (Product $product): bool => $product->shortage_policy === 'backorder');
        $imageProduct = $members->first(fn (Product $product): bool => $product->images->isNotEmpty());
        $definitions = $members->flatMap->attributeValues->filter(fn ($value): bool => $value->definition?->status === 'active')
            ->sortBy(fn ($value): array => [$value->definition->sort_order, $value->definition->id])->groupBy('product_attribute_definition_id');
        $attributes = $definitions->take(2)->map(fn (Collection $values): array => [
            'name' => $values->first()->definition->name,
            'values' => $values->pluck('value')->unique()->take(4)->values()->all(),
            'has_more' => $values->pluck('value')->unique()->count() > 4,
        ])->values()->all();

        return array_merge($first->toArray(), [
            'group_key' => $this->key($first->product_group_id, $first->id),
            'name' => trim((string) $first->group?->name) !== '' ? $first->group->name : $first->name,
            'variant_count' => $members->count(),
            'variants' => $members->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit->toArray(),
                'available_quantity' => $product->available_quantity + 0,
                'shortage_policy' => $product->shortage_policy,
                'price_including_tax' => $variantPrices->get($product->id),
                'images' => $product->images->take(1)->values()->toArray(),
                'attribute_values' => $product->attributeValues->filter(fn ($value): bool => $value->definition?->status === 'active')
                    ->sortBy(fn ($value): array => [$value->definition->sort_order, $value->definition->id])
                    ->map(fn ($value): array => ['value' => $value->value])->values()->all(),
            ])->values()->all(),
            'price_including_tax' => $prices->first(),
            'max_price_including_tax' => $prices->last(),
            'has_price_range' => bccomp($prices->first(), $prices->last(), 4) !== 0,
            'images' => $imageProduct?->images->toArray() ?? [],
            'available_quantity' => $first->available_quantity + 0,
            'stock_status' => $hasStock ? 'available' : ($backorder ? 'backorder' : 'sold_out'),
            'can_backorder' => $backorder,
            'attribute_summary' => $attributes,
            'categories' => $members->flatMap->categories->unique('id')->values()->toArray(),
        ]);
    }
}
