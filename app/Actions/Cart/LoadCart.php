<?php

namespace App\Actions\Cart;

use App\Models\Product;
use Illuminate\Support\Collection;

class LoadCart
{
    /** @param array<int|string, string|int|float> $cart @return array{items: Collection<int, array{product: Product, quantity: string, amount: string}>, total: string} */
    public function handle(array $cart): array
    {
        $products = Product::whereIn('id', array_keys($cart))
            ->with(['unit:id,name,code,quantity_scale', 'categories:id,name', 'bundleComponents.componentProduct.inventoryLots'])
            ->withSum(['inventoryLots as available_quantity' => fn ($query) => $query->where('current_quantity', '>', 0)], 'current_quantity')
            ->get()->keyBy('id');
        $total = '0';
        $items = collect($cart)->map(function ($quantity, $productId) use ($products, &$total): ?array {
            $product = $products->get((int) $productId);
            if (! $product) {
                return null;
            }
            $product->setAttribute('available_quantity', $product->availableQuantity());
            $amount = $product->default_sale_price === null ? '0.0000' : bcmul((string) $quantity, (string) $product->default_sale_price, 4);
            $total = bcadd($total, $amount, 4);

            return ['product' => $product, 'quantity' => (string) $quantity, 'amount' => $amount];
        })->filter()->values();

        return compact('items', 'total');
    }
}
