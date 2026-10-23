<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShipmentManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{admin: User, customer: User, order: Order, line: OrderLine, product: Product} */
    private function context(string $status = 'confirmed', string $quantity = '5'): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['name' => '発送先 顧客']);
        $unit = Unit::factory()->create(['name' => '個', 'quantity_scale' => 0]);
        $product = Product::factory()->create(['unit_id' => $unit->id, 'registration_status' => 'confirmed', 'default_sale_price' => '500']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'status' => $status, 'subtotal' => bcmul($quantity, '500', 4), 'total' => bcmul($quantity, '500', 4)]);
        $line = OrderLine::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'product_name' => '発送テスト商品',
            'unit_name' => '個',
            'quantity' => $quantity,
            'unit_sale_price' => '500',
            'line_amount' => bcmul($quantity, '500', 4),
        ]);

        return compact('admin', 'customer', 'order', 'line', 'product');
    }

    private function input(array $context, string $quantity, string $action = 'draft', array $overrides = []): array
    {
        return array_replace([
            'order_id' => $context['order']->id,
            'action' => $action,
            'carrier' => $action === 'ship' ? 'ヤマト運輸' : null,
            'tracking_number' => 'TRACK-001',
            'scheduled_on' => '2026-10-23',
            'shipped_on' => $action === 'ship' ? '2026-10-23' : null,
            'notes' => '発送テスト',
            'lock_version' => 0,
            'lines' => [['order_line_id' => $context['line']->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    public function test_shipment_routes_require_verified_admin(): void
    {
        $context = $this->context();

        $this->get('/admin/shipments')->assertRedirect('/admin/login');
        $this->post('/admin/shipments', $this->input($context, '1'))->assertRedirect('/admin/login');

        $this->actingAs($context['customer'])->get('/admin/shipments')->assertForbidden();
        $this->post('/admin/shipments', $this->input($context, '1'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create())->get('/admin/shipments')->assertRedirect('/email/verify');
    }

    public function test_admin_creates_draft_shipment_and_order_enters_preparing(): void
    {
        $context = $this->context();

        $response = $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '2'));

        $shipment = Shipment::firstOrFail();
        $response->assertRedirect('/admin/shipments/'.$shipment->id.'/edit')->assertSessionHasNoErrors();
        $this->assertSame('draft', $shipment->status);
        $this->assertSame('preparing', $context['order']->fresh()->status);
        $this->assertDatabaseHas('shipment_lines', ['shipment_id' => $shipment->id, 'order_line_id' => $context['line']->id, 'quantity' => '2.0000']);
    }

    public function test_shipment_rejects_another_order_line_and_quantity_over_remaining(): void
    {
        $context = $this->context();
        $other = $this->context();

        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '1', 'draft', [
            'lines' => [['order_line_id' => $other['line']->id, 'quantity' => '1']],
        ]))->assertSessionHasErrors(['lines.0.order_line_id' => 'この注文の商品を選択してください。']);

        $this->post('/admin/shipments', $this->input($context, '6'))
            ->assertSessionHasErrors(['lines.0.quantity' => '未発送数量（5.0000 個）以内で入力してください。']);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_partial_then_remaining_shipment_changes_order_to_shipped(): void
    {
        $context = $this->context();
        $this->freezeTime();

        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '2', 'ship'))->assertSessionHasNoErrors();

        $first = Shipment::firstOrFail();
        $this->assertSame('shipped', $first->status);
        $this->assertSame($context['admin']->id, $first->shipped_by);
        $this->assertTrue($first->shipped_at->equalTo(now()));
        $this->assertSame('preparing', $context['order']->fresh()->status);

        $this->post('/admin/shipments', $this->input($context, '3', 'ship', ['tracking_number' => 'TRACK-002']))->assertSessionHasNoErrors();

        $this->assertSame('shipped', $context['order']->fresh()->status);
        $this->assertDatabaseCount('shipments', 2);
        $this->assertDatabaseHas('shipments', ['tracking_number' => 'TRACK-002', 'status' => 'shipped']);
    }

    public function test_pending_backorder_prevents_shipping_confirmation(): void
    {
        $context = $this->context();
        $context['line']->backorders()->create([
            'stock_product_id' => $context['product']->id,
            'stock_product_sku' => $context['product']->sku,
            'stock_product_name' => $context['product']->name,
            'unit_name' => '個',
            'required_quantity' => '5',
            'initial_quantity' => '2',
            'remaining_quantity' => '2',
        ]);

        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '3', 'ship'))
            ->assertSessionHasErrors(['status' => '取り寄せ商品が揃うまで発送を確定できません。']);

        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame('confirmed', $context['order']->fresh()->status);
    }

    public function test_confirmed_shipment_cannot_be_edited_or_cancelled(): void
    {
        $context = $this->context();
        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '5', 'ship'))->assertSessionHasNoErrors();
        $shipment = Shipment::firstOrFail();

        $this->put("/admin/shipments/{$shipment->id}", $this->input($context, '4', 'draft'))
            ->assertSessionHasErrors('order_id');
        $this->post("/admin/shipments/{$shipment->id}/cancel", ['reason' => '誤り'])
            ->assertSessionHasErrors(['status' => '下書きの発送だけ取り消せます。']);

        $this->assertSame('shipped', $shipment->fresh()->status);
        $this->assertSame('5.0000', $shipment->lines()->firstOrFail()->quantity);
    }

    public function test_cancelling_draft_releases_quantity_for_another_shipment(): void
    {
        $context = $this->context();
        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '5'))->assertSessionHasNoErrors();
        $shipment = Shipment::firstOrFail();

        $this->post("/admin/shipments/{$shipment->id}/cancel", ['reason' => '伝票を作り直す'])->assertSessionHasNoErrors();
        $this->post('/admin/shipments', $this->input($context, '5'))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $shipment->fresh()->status);
        $this->assertDatabaseCount('shipments', 2);
    }

    public function test_order_cancellation_also_cancels_its_draft_shipments(): void
    {
        $context = $this->context();
        $this->actingAs($context['admin'])->post('/admin/shipments', $this->input($context, '5'))->assertSessionHasNoErrors();
        $shipment = Shipment::firstOrFail();

        $this->post("/admin/orders/{$context['order']->id}/cancel", ['reason' => '注文自体を取消'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $context['order']->fresh()->status);
        $this->assertSame('cancelled', $shipment->fresh()->status);
        $this->assertSame('注文キャンセル：注文自体を取消', $shipment->fresh()->cancellation_reason);
    }

    public function test_order_with_shipped_record_cannot_be_cancelled(): void
    {
        $context = $this->context();
        Shipment::factory()->create([
            'order_id' => $context['order']->id,
            'status' => 'shipped',
            'shipped_on' => '2026-10-23',
            'created_by' => $context['admin']->id,
            'shipped_by' => $context['admin']->id,
            'shipped_at' => now(),
        ]);

        $this->actingAs($context['admin'])->post("/admin/orders/{$context['order']->id}/cancel", ['reason' => '取消不可'])
            ->assertSessionHasErrors(['status' => '発送済みの伝票がある注文はキャンセルできません。']);

        $this->assertSame('confirmed', $context['order']->fresh()->status);
    }

    public function test_customer_order_detail_contains_shipped_tracking_information(): void
    {
        $context = $this->context('shipped');
        $shipment = Shipment::factory()->create([
            'order_id' => $context['order']->id,
            'status' => 'shipped',
            'carrier' => '佐川急便',
            'tracking_number' => 'CUSTOMER-TRACK',
            'shipped_on' => '2026-10-23',
            'created_by' => $context['admin']->id,
            'shipped_by' => $context['admin']->id,
            'shipped_at' => now(),
        ]);
        ShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $context['line']->id, 'quantity' => '5']);

        $this->actingAs($context['customer'])->get('/orders/'.$context['order']->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('order.shipments.0.carrier', '佐川急便')
            ->where('order.shipments.0.tracking_number', 'CUSTOMER-TRACK'));
    }

    public function test_shipment_index_renders_for_admin(): void
    {
        $context = $this->context();
        Shipment::factory()->create(['order_id' => $context['order']->id, 'created_by' => $context['admin']->id]);

        $this->actingAs($context['admin'])->get('/admin/shipments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Shipments/Index')
            ->where('shipments.total', 1)
            ->where('counts.draft', 1));
    }
}
