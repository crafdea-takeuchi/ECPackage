<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdminProductStock
{
    /** Match the existing selling quantity calculation, including bundle components. */
    public static function query(): Builder
    {
        $stock = DB::table('inventory_lots')->select('product_id')
            ->selectRaw('SUM(current_quantity) AS quantity')->where('current_quantity', '>', 0)->groupBy('product_id');
        $bundles = DB::table('product_bundle_components as component')
            ->leftJoinSub(clone $stock, 'component_stock', 'component_stock.product_id', '=', 'component.component_product_id')
            ->select('component.bundle_product_id')
            ->selectRaw('MIN(FLOOR(COALESCE(component_stock.quantity, 0) / component.quantity)) AS quantity')
            ->groupBy('component.bundle_product_id');

        return Product::query()->leftJoinSub($stock, 'stock', 'stock.product_id', '=', 'products.id')
            ->leftJoinSub($bundles, 'bundle_stock', 'bundle_stock.bundle_product_id', '=', 'products.id')
            ->select('products.*')->selectRaw(self::expression().' AS available_quantity');
    }

    public static function expression(): string
    {
        return "CASE WHEN products.kind = 'bundle' THEN COALESCE(bundle_stock.quantity, 0) ELSE COALESCE(stock.quantity, 0) END";
    }
}
