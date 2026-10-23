<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{customer: User, admin: User, product: Product, lots: array<int, InventoryLot>} */
    private function orderContext(array $quantities = ['10'], int $quantityScale = 0): array
    {
        $customer = User::factory()->create(['name' => '山田 顧客', 'email' => 'customer@example.test']);
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $unit = Unit::factory()->create(['name' => $quantityScale === 0 ? '個' : 'kg', 'quantity_scale' => $quantityScale]);
        $product = Product::factory()->create([
            'sku' => 'ORDER-PRODUCT',
            'name' => '注文テスト商品',
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'default_sale_price' => '250.5000',
        ]);
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'status' => 'posted']);
        $lots = [];
        foreach ($quantities as $index => $quantity) {
            $line = PurchaseReceiptLine::factory()->create([
                'purchase_receipt_id' => $receipt->id,
                'line_number' => $index + 1,
                'product_id' => $product->id,
                'product_supplier_id' => $setting->id,
                'lot_number' => 'ORDER-LOT-'.($index + 1),
                'quantity' => $quantity,
                'unit_purchase_price' => 100 + $index,
                'purchase_amount' => bcmul($quantity, (string) (100 + $index), 4),
            ]);
            $lots[] = InventoryLot::factory()->create([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'purchase_receipt_line_id' => $line->id,
                'lot_number' => 'ORDER-LOT-'.($index + 1),
                'received_on' => '2026-10-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'expires_on' => $index === 0 ? '2026-11-30' : null,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'unit_purchase_price' => 100 + $index,
            ]);
        }

        return compact('customer', 'admin', 'product', 'lots');
    }

    private function input(User $customer, Product $product, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customer->id,
            'ordered_on' => '2026-10-21',
            'notes' => '営業による代理注文',
            'action' => 'draft',
            'lock_version' => 0,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_sale_price' => '250.5000',
            ]],
        ], $overrides);
    }

    public function test_order_routes_require_verified_admin(): void
    {
        $context = $this->orderContext();
        $order = Order::factory()->create(['customer_id' => $context['customer']->id]);

        $this->get('/admin/orders')->assertRedirect('/admin/login');
        $this->get('/admin/orders/create')->assertRedirect('/admin/login');
        $this->post('/admin/orders', $this->input($context['customer'], $context['product']))->assertRedirect('/admin/login');
        $this->get('/admin/orders/'.$order->id.'/edit')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/orders')->assertForbidden();
        $this->get('/admin/orders/create')->assertForbidden();
        $this->post('/admin/orders', $this->input($context['customer'], $context['product']))->assertForbidden();
        $this->get('/admin/orders/'.$order->id.'/edit')->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/orders')->assertRedirect('/email/verify');
        $this->post('/admin/orders', $this->input($context['customer'], $context['product']))->assertRedirect('/email/verify');
    }

    public function test_admin_saves_draft_with_price_snapshot_without_reducing_stock(): void
    {
        $context = $this->orderContext(['10']);

        $response = $this->actingAs($context['admin'])->post('/admin/orders', $this->input($context['customer'], $context['product']));

        $order = Order::firstOrFail();
        $response->assertRedirect('/admin/orders/'.$order->id.'/edit')->assertSessionHasNoErrors();
        $this->assertSame('draft', $order->status);
        $this->assertSame('501.0000', $order->total);
        $this->assertDatabaseHas('order_lines', [
            'order_id' => $order->id,
            'product_id' => $context['product']->id,
            'product_sku' => 'ORDER-PRODUCT',
            'product_name' => '注文テスト商品',
            'quantity' => '2.0000',
            'unit_sale_price' => '250.5000',
            'line_amount' => '501.0000',
        ]);
        $this->assertSame('10.0000', $context['lots'][0]->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('order_allocations', 0);
    }

    public function test_confirmed_order_allocates_fefo_across_lots_and_creates_outbound(): void
    {
        $context = $this->orderContext(['3', '10']);
        $input = $this->input($context['customer'], $context['product'], ['action' => 'confirm', 'lines' => [[
            'product_id' => $context['product']->id, 'quantity' => '5', 'unit_sale_price' => '250.5',
        ]]]);

        $this->actingAs($context['admin'])->post('/admin/orders', $input)->assertSessionHasNoErrors();

        $order = Order::with('lines.allocations')->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->stock_operation_id);
        $this->assertSame('0.0000', $context['lots'][0]->fresh()->current_quantity);
        $this->assertSame('8.0000', $context['lots'][1]->fresh()->current_quantity);
        $this->assertSame('depleted', $context['lots'][0]->fresh()->status);
        $this->assertDatabaseHas('order_allocations', ['inventory_lot_id' => $context['lots'][0]->id, 'quantity' => '3.0000', 'unit_cost' => '100.0000']);
        $this->assertDatabaseHas('order_allocations', ['inventory_lot_id' => $context['lots'][1]->id, 'quantity' => '2.0000', 'unit_cost' => '101.0000']);
        $this->assertDatabaseCount('stock_operation_lines', 2);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $context['lots'][0]->id, 'movement_type' => 'outbound', 'quantity_delta' => '-3.0000']);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $context['lots'][1]->id, 'movement_type' => 'outbound', 'quantity_delta' => '-2.0000']);
    }

    public function test_insufficient_stock_rolls_back_new_order_and_every_inventory_change(): void
    {
        $context = $this->orderContext(['2', '1']);
        $input = $this->input($context['customer'], $context['product'], ['action' => 'confirm', 'lines' => [[
            'product_id' => $context['product']->id, 'quantity' => '4', 'unit_sale_price' => '250.5',
        ]]]);

        $this->actingAs($context['admin'])->post('/admin/orders', $input)
            ->assertSessionHasErrors(['status' => '注文テスト商品 の在庫が不足しています（必要 4.0000、引当可能 3.0000）。']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('2.0000', $context['lots'][0]->fresh()->current_quantity);
        $this->assertSame('1.0000', $context['lots'][1]->fresh()->current_quantity);
    }

    public function test_draft_update_uses_lock_and_can_then_be_confirmed(): void
    {
        $context = $this->orderContext(['10']);
        $order = Order::factory()->create(['customer_id' => $context['customer']->id, 'created_by' => $context['admin']->id, 'lock_version' => 1]);
        $line = OrderLine::factory()->create([
            'order_id' => $order->id,
            'product_id' => $context['product']->id,
            'product_sku' => $context['product']->sku,
            'product_name' => $context['product']->name,
        ]);

        $this->actingAs($context['admin'])->put('/admin/orders/'.$order->id, $this->input($context['customer'], $context['product'], [
            'lock_version' => 0, 'lines' => [['id' => $line->id, 'product_id' => $context['product']->id, 'quantity' => '2', 'unit_sale_price' => '250.5']],
        ]))->assertSessionHasErrors('lock_version');

        $this->put('/admin/orders/'.$order->id, $this->input($context['customer'], $context['product'], [
            'action' => 'confirm', 'lock_version' => 1, 'lines' => [['id' => $line->id, 'product_id' => $context['product']->id, 'quantity' => '2', 'unit_sale_price' => '300']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(2, $order->fresh()->lock_version);
        $this->assertSame('600.0000', $order->fresh()->total);
        $this->assertSame('8.0000', $context['lots'][0]->fresh()->current_quantity);
    }

    public function test_confirmed_order_cannot_be_changed_or_dispatched_twice(): void
    {
        $context = $this->orderContext(['10']);
        $this->actingAs($context['admin'])->post('/admin/orders', $this->input($context['customer'], $context['product'], ['action' => 'confirm']))->assertSessionHasNoErrors();
        $order = Order::with('lines')->firstOrFail();

        $this->put('/admin/orders/'.$order->id, $this->input($context['customer'], $context['product'], [
            'action' => 'confirm', 'lock_version' => $order->lock_version, 'lines' => [['id' => $order->lines[0]->id, 'product_id' => $context['product']->id, 'quantity' => '5', 'unit_sale_price' => '100']],
        ]))->assertSessionHasErrors('status');

        $this->assertSame('8.0000', $context['lots'][0]->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('order_allocations', 1);
    }

    public function test_invalid_customer_duplicate_product_and_quantity_scale_are_rejected(): void
    {
        $context = $this->orderContext(['10'], 0);
        $otherAdmin = User::factory()->admin()->create();
        $duplicate = $this->input($context['customer'], $context['product'], ['lines' => [
            ['product_id' => $context['product']->id, 'quantity' => '1', 'unit_sale_price' => '100'],
            ['product_id' => $context['product']->id, 'quantity' => '1', 'unit_sale_price' => '100'],
        ]]);
        $this->actingAs($context['admin'])->post('/admin/orders', $duplicate)->assertSessionHasErrors('lines.1.product_id');

        $this->post('/admin/orders', $this->input($otherAdmin, $context['product']))->assertSessionHasErrors('customer_id');
        $this->post('/admin/orders', $this->input($context['customer'], $context['product'], ['lines' => [[
            'product_id' => $context['product']->id, 'quantity' => '1.5', 'unit_sale_price' => '100',
        ]]]))->assertSessionHasErrors(['lines.0.quantity' => 'この販売商品の数量は整数で入力してください。']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_list_filters_and_detail_includes_lot_allocations(): void
    {
        $context = $this->orderContext(['10']);
        $this->actingAs($context['admin'])->post('/admin/orders', $this->input($context['customer'], $context['product'], ['action' => 'confirm']))->assertSessionHasNoErrors();
        $match = Order::firstOrFail();
        Order::factory()->create(['ordered_on' => '2026-09-01', 'status' => 'draft']);

        $this->get('/admin/orders?q=注文テスト&customer_id='.$context['customer']->id.'&status=confirmed&ordered_month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders/Index')
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $match->id)
                ->where('orders.data.0.lines_count', 1)
                ->where('counts.all', 2));

        $this->get('/admin/orders/'.$match->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders/Form')
                ->where('order.status', 'confirmed')
                ->where('order.lines.0.allocations.0.inventory_lot.lot_number', $context['lots'][0]->lot_number)
                ->where('order.stock_operation.operation_number', fn (string $number): bool => str_starts_with($number, 'SO-')));
    }
}
