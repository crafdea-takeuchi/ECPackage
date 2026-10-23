<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\BackorderReady;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(string $stock = '2', string $policy = 'reject'): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $unit = Unit::factory()->create(['quantity_scale' => 0]);
        $product = Product::factory()->create(['unit_id' => $unit->id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 100, 'shortage_policy' => $policy]);
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'status' => 'posted']);
        $receiptLine = PurchaseReceiptLine::factory()->create(['purchase_receipt_id' => $receipt->id, 'product_id' => $product->id, 'product_supplier_id' => $setting->id, 'quantity' => $stock, 'purchase_amount' => bcmul($stock, '50', 4)]);
        $lot = InventoryLot::factory()->create(['product_id' => $product->id, 'supplier_id' => $supplier->id, 'purchase_receipt_line_id' => $receiptLine->id, 'initial_quantity' => $stock, 'current_quantity' => $stock]);

        return compact('admin', 'customer', 'supplier', 'product', 'setting', 'lot');
    }

    private function orderInput(array $context, string $quantity): array
    {
        return ['customer_id' => $context['customer']->id, 'ordered_on' => '2026-10-21', 'action' => 'confirm', 'lock_version' => 0, 'lines' => [['product_id' => $context['product']->id, 'quantity' => $quantity, 'unit_sale_price' => 100]]];
    }

    public function test_backorder_policy_confirms_available_quantity_and_records_shortage(): void
    {
        $context = $this->context('2', 'backorder');
        $this->actingAs($context['admin'])->post('/admin/orders', $this->orderInput($context, '5'))->assertSessionHasNoErrors();

        $order = Order::with('lines.allocations')->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('3.0000', $order->lines[0]->backordered_initial_quantity);
        $this->assertSame('3.0000', $order->lines[0]->backordered_quantity);
        $this->assertEquals('2.0000', $order->lines[0]->allocations->sum('quantity'));
        $this->assertSame('0.0000', $context['lot']->fresh()->current_quantity);
    }

    public function test_cancel_returns_each_allocation_to_original_lot_and_keeps_audit_history(): void
    {
        $context = $this->context('5');
        $this->actingAs($context['admin'])->post('/admin/orders', $this->orderInput($context, '3'))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();

        $this->post("/admin/orders/{$order->id}/cancel", ['reason' => 'お客様都合'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('5.0000', $context['lot']->fresh()->current_quantity);
        $this->assertNotNull($order->cancellation_stock_operation_id);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $context['lot']->id, 'movement_type' => 'outbound', 'quantity_delta' => '-3.0000']);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $context['lot']->id, 'movement_type' => 'cancellation_return', 'quantity_delta' => '3.0000']);
    }

    public function test_status_changes_are_sequential_and_cancellation_states_are_configurable(): void
    {
        $context = $this->context('5');
        $this->actingAs($context['admin'])->post('/admin/orders', $this->orderInput($context, '1'))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();

        $this->patch("/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertSessionHasErrors('status');
        $this->patch("/admin/orders/{$order->id}/status", ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->put('/admin/order-settings', ['cancellable_statuses' => ['draft']])->assertSessionHasNoErrors();
        $this->post("/admin/orders/{$order->id}/cancel", ['reason' => '不可状態'])->assertSessionHasErrors('status');
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame('4.0000', $context['lot']->fresh()->current_quantity);
    }

    public function test_customer_can_order_more_than_stock_when_product_allows_backorders(): void
    {
        $context = $this->context('2', 'backorder');

        $this->actingAs($context['customer'])->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '5'])->assertSessionHasNoErrors();
        $this->post('/checkout', ['notes' => '取り寄せ可'])->assertSessionHasNoErrors();

        $order = Order::with('lines')->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('3.0000', $order->lines[0]->backordered_quantity);
        $this->assertSame('0.0000', $context['lot']->fresh()->current_quantity);
        $this->assertNull(session('cart'));
    }

    public function test_posting_receipt_automatically_allocates_oldest_backorder_and_notifies_customer(): void
    {
        Notification::fake();
        $context = $this->context('2', 'backorder');
        $this->actingAs($context['customer'])->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '5'])->assertSessionHasNoErrors();
        $this->post('/checkout', ['notes' => null])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();

        $input = [
            'supplier_id' => $context['supplier']->id,
            'received_on' => '2026-10-22',
            'action' => 'post',
            'lock_version' => 0,
            'lines' => [[
                'product_id' => $context['product']->id,
                'product_supplier_id' => $context['setting']->id,
                'lot_number' => 'BACKORDER-ARRIVAL',
                'manufactured_on' => null,
                'expires_on' => null,
                'quantity' => '4',
                'unit_purchase_price' => '60',
            ]],
        ];
        $this->actingAs($context['admin'])->post('/admin/purchase-receipts', $input)->assertSessionHasNoErrors();

        $line = $order->lines()->firstOrFail();
        $newLot = InventoryLot::where('lot_number', 'BACKORDER-ARRIVAL')->firstOrFail();
        $this->assertSame('3.0000', $line->backordered_initial_quantity);
        $this->assertSame('0.0000', $line->backordered_quantity);
        $this->assertNotNull($line->backorder_fulfilled_at);
        $this->assertNotNull($order->fresh()->backorder_completed_at);
        $this->assertSame('1.0000', $newLot->current_quantity);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $newLot->id, 'movement_type' => 'backorder_allocation', 'quantity_delta' => '-3.0000']);
        Notification::assertSentTo($context['customer'], BackorderReady::class);
    }

    public function test_order_cannot_be_marked_shipped_without_a_shipment(): void
    {
        $context = $this->context('0', 'backorder');
        $this->actingAs($context['admin'])->post('/admin/orders', $this->orderInput($context, '2'))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();

        $this->patch("/admin/orders/{$order->id}/status", ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch("/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertSessionHasErrors(['status' => '発送管理で発送を確定してください。']);
        $this->assertSame('preparing', $order->fresh()->status);
    }
}
