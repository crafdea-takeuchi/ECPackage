<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductCatalogSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_the_registered_catalog_without_duplicates(): void
    {
        User::factory()->admin()->create();

        $this->seed(ProductCatalogSeeder::class);
        Product::query()->where('sku', 'P-01M60RDMQMSM93NWDVH9JYMR8D')->firstOrFail()->inventoryLots()->firstOrFail()->update(['current_quantity' => '7.0000']);
        $this->seed(ProductCatalogSeeder::class);

        $this->assertSame(3, Product::query()->count());
        $this->assertSame(3, Product::query()->where('publication_status', 'public')->count());
        $this->assertSame(0, Product::query()->whereNotNull('product_group_id')->count());

        $potato = Product::query()->where('sku', 'P-01M60RDMQMSM93NWDVH9JYMR8D')->firstOrFail();
        $redMoon = Product::query()->where('sku', 'P-01M60RQYSHAECYSMA5WAQMP2PZ')->firstOrFail();
        $fryingPan = Product::query()->where('sku', 'P-01M60RZ2S56VTP2C0A877WT8FM')->firstOrFail();

        $this->assertSame('長崎こがね', $potato->categories()->firstOrFail()->name);
        $this->assertSame('ptt1-20261028-02', $potato->inventoryLots()->sole()->lot_number);
        $this->assertSame('7.0000', $potato->inventoryLots()->sole()->current_quantity);
        $this->assertSame('ptt1-20261028-01', $redMoon->inventoryLots()->sole()->lot_number);
        $this->assertSame('フライパン', $fryingPan->categories()->firstOrFail()->name);
        $this->assertSame('frp1-20261028-01', $fryingPan->inventoryLots()->sole()->lot_number);

        $this->assertDatabaseCount('categories', 8);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('product_suppliers', 3);
        $this->assertDatabaseCount('purchase_receipts', 3);
        $this->assertDatabaseCount('purchase_receipt_lines', 3);
        $this->assertDatabaseCount('inventory_lots', 3);
        $this->assertDatabaseCount('stock_movements', 3);
    }
}
