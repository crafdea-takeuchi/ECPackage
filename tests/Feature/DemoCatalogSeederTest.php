<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductGroup;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DemoCatalogSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_catalog_seeder_creates_related_data_and_is_idempotent(): void
    {
        User::factory()->admin()->create();

        $this->seed(DemoCatalogSeeder::class);
        $lot = InventoryLot::where('lot_number', 'DEMO-GRAPE-202610')->firstOrFail();
        $lot->update(['current_quantity' => '7']);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(7, Category::whereIn('name', ['見本商品', 'お菓子', 'グミ', '野菜', '根菜', 'ジャガイモ', '飲料'])->count());
        $this->assertSame(2, ProductGroup::where('name', 'like', '【見本】%')->count());
        $this->assertSame(5, ProductAttributeDefinition::whereHas('group', fn ($query) => $query->where('name', 'like', '【見本】%'))->count());
        $this->assertSame(10, Product::where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame(3, Supplier::where('code', 'like', 'DEMO-%')->count());
        $this->assertSame(9, ProductSupplier::whereHas('supplier', fn ($query) => $query->where('code', 'like', 'DEMO-%'))->count());
        $this->assertSame(3, PurchaseReceipt::where('receipt_number', 'like', 'DEMO-R-%')->count());
        $this->assertSame(2, PurchaseReceipt::where('receipt_number', 'like', 'DEMO-R-%')->where('status', 'posted')->count());
        $this->assertSame(3, InventoryLot::where('lot_number', 'like', 'DEMO-%')->count());
        $this->assertSame(3, StockMovement::where('source_type', 'purchase_receipt_line')->count());
        $this->assertSame(2, StockOperation::where('operation_number', 'like', 'DEMO-SO-%')->count());
        $this->assertSame(5, StockMovement::where('source_type', 'stock_operation_line')->count());
        $this->assertSame(3, Order::where('order_number', 'like', 'DEMO-O-%')->count());
        $this->assertSame(1, Order::where('order_number', 'like', 'DEMO-O-%')->where('status', 'preparing')->count());
        $this->assertSame(1, Order::where('order_number', 'like', 'DEMO-O-%')->where('status', 'shipped')->count());
        $this->assertSame('7.0000', $lot->fresh()->current_quantity);
        $this->assertSame(2, Shipment::where('shipment_number', 'like', 'DEMO-SH-%')->count());
        $this->assertSame(1, Shipment::where('shipment_number', 'like', 'DEMO-SH-%')->where('status', 'draft')->count());
        $this->assertSame(1, Shipment::where('shipment_number', 'like', 'DEMO-SH-%')->where('status', 'shipped')->count());
        $this->assertSame(2, ShipmentLine::whereHas('shipment', fn ($query) => $query->where('shipment_number', 'like', 'DEMO-SH-%'))->count());

        $product = Product::where('sku', 'DEMO-POTATO-DAN-HKD-10')->firstOrFail();
        $this->assertSame(2, $product->supplierSettings()->count());
        $this->assertSame(1, $product->supplierSettings()->where('is_primary', true)->count());
        $this->assertSame(3, $product->attributeValues()->count());
        $this->assertSame(3, $product->categories()->count());

        $provisional = Product::where('sku', 'DEMO-GUMMY-LEMON')->firstOrFail();
        $this->assertSame('provisional', $provisional->registration_status);
        $this->assertNull($provisional->default_sale_price);
        $this->assertSame(0, $provisional->attributeValues()->count());

        $bundle = Product::where('sku', 'DEMO-SET-GUMMY-VARIETY')->firstOrFail();
        $this->assertSame('bundle', $bundle->kind);
        $this->assertSame(2, $bundle->bundleComponents()->count());
    }
}
