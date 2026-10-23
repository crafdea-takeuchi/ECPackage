<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BundleProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{admin: User, customer: User, piece: Unit, set: Unit, first: Product, second: Product} */
    private function context(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $piece = Unit::factory()->create(['code' => 'test-piece', 'name' => '個', 'quantity_scale' => 0]);
        $set = Unit::where('code', 'set')->firstOrFail();
        $first = Product::factory()->create(['sku' => 'PART-A', 'name' => '構成品A', 'kind' => 'single', 'unit_id' => $piece->id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => '100']);
        $second = Product::factory()->create(['sku' => 'PART-B', 'name' => '構成品B', 'kind' => 'single', 'unit_id' => $piece->id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => '200']);

        return compact('admin', 'customer', 'piece', 'set', 'first', 'second');
    }

    private function createBundle(array $context, string $shortagePolicy = 'reject'): Product
    {
        $this->actingAs($context['admin'])->post('/admin/products', [
            'kind' => 'bundle', 'name' => 'お試しセット', 'sku' => 'BUNDLE-01', 'unit_id' => $context['set']->id,
            'registration_status' => 'confirmed', 'publication_status' => 'public', 'shortage_policy' => $shortagePolicy,
            'default_sale_price' => '550', 'category_ids' => [], 'attribute_values' => [],
            'bundle_components' => [
                ['component_product_id' => $context['first']->id, 'quantity' => '2'],
                ['component_product_id' => $context['second']->id, 'quantity' => '1'],
            ],
        ])->assertSessionHasNoErrors();

        return Product::where('sku', 'BUNDLE-01')->firstOrFail();
    }

    public function test_admin_can_register_bundle_and_shop_shows_derived_availability(): void
    {
        $context = $this->context();
        $bundle = $this->createBundle($context);
        InventoryLot::factory()->create(['product_id' => $context['first']->id, 'initial_quantity' => 7, 'current_quantity' => 7]);
        InventoryLot::factory()->create(['product_id' => $context['second']->id, 'initial_quantity' => 5, 'current_quantity' => 5]);

        $this->assertDatabaseHas('product_bundle_components', ['bundle_product_id' => $bundle->id, 'component_product_id' => $context['first']->id, 'quantity' => '2.0000']);
        $this->assertSame(3, $bundle->availableQuantity());
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.kind', 'bundle')
            ->where('products.data.0.available_quantity', 3)
            ->has('products.data.0.bundle_components', 2));
    }

    public function test_bundle_rejects_nested_or_duplicate_components(): void
    {
        $context = $this->context();
        $bundle = $this->createBundle($context);

        $this->post('/admin/products', [
            'kind' => 'bundle', 'name' => '不正セット', 'unit_id' => $context['set']->id, 'registration_status' => 'confirmed',
            'publication_status' => 'private', 'shortage_policy' => 'reject', 'default_sale_price' => '1',
            'bundle_components' => [
                ['component_product_id' => $bundle->id, 'quantity' => '1'],
                ['component_product_id' => $bundle->id, 'quantity' => '1'],
            ],
        ])->assertSessionHasErrors(['bundle_components.1.component_product_id', 'bundle_components.0.component_product_id']);
    }

    public function test_confirm_and_cancel_bundle_order_deducts_and_restores_component_lots(): void
    {
        $context = $this->context();
        $bundle = $this->createBundle($context);
        $firstLot = InventoryLot::factory()->create(['product_id' => $context['first']->id, 'initial_quantity' => 10, 'current_quantity' => 10]);
        $secondLot = InventoryLot::factory()->create(['product_id' => $context['second']->id, 'initial_quantity' => 10, 'current_quantity' => 10]);

        $this->post('/admin/orders', [
            'customer_id' => $context['customer']->id, 'ordered_on' => '2026-10-22', 'action' => 'confirm', 'notes' => '',
            'lines' => [['product_id' => $bundle->id, 'quantity' => '2', 'unit_sale_price' => '550']],
        ])->assertSessionHasNoErrors();

        $order = Order::with('lines.components', 'lines.allocations')->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertCount(2, $order->lines->first()->components);
        $this->assertSame('6.0000', $firstLot->fresh()->current_quantity);
        $this->assertSame('8.0000', $secondLot->fresh()->current_quantity);
        $this->assertDatabaseHas('order_line_components', ['order_line_id' => $order->lines->first()->id, 'component_product_id' => $context['first']->id, 'quantity_per_set' => '2.0000', 'required_quantity' => '4.0000']);

        $this->post("/admin/orders/{$order->id}/cancel", ['reason' => 'テスト取消'])->assertSessionHasNoErrors();
        $this->assertSame('10.0000', $firstLot->fresh()->current_quantity);
        $this->assertSame('10.0000', $secondLot->fresh()->current_quantity);
    }

    public function test_bundle_shortage_is_recorded_for_the_missing_component(): void
    {
        $context = $this->context();
        $bundle = $this->createBundle($context, 'backorder');
        InventoryLot::factory()->create(['product_id' => $context['first']->id, 'initial_quantity' => 4, 'current_quantity' => 4]);
        InventoryLot::factory()->create(['product_id' => $context['second']->id, 'initial_quantity' => 1, 'current_quantity' => 1]);

        $this->post('/admin/orders', [
            'customer_id' => $context['customer']->id, 'ordered_on' => '2026-10-22', 'action' => 'confirm', 'notes' => '',
            'lines' => [['product_id' => $bundle->id, 'quantity' => '2', 'unit_sale_price' => '550']],
        ])->assertSessionHasNoErrors();

        $order = Order::with('lines')->firstOrFail();
        $this->assertDatabaseHas('order_backorders', [
            'order_line_id' => $order->lines->first()->id,
            'stock_product_id' => $context['second']->id,
            'initial_quantity' => '1.0000',
            'remaining_quantity' => '1.0000',
        ]);
    }
}
