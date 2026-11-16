<?php

namespace Tests\Feature;

use App\Actions\Orders\ConfirmOrder;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\BankTransfer;
use Tests\TestCase;

class BankTransferOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function context(bool $registered = true): array
    {
        if ($registered) {
            BankTransfer::configure();
        }
        $customer = User::factory()->create();
        $product = Product::factory()->create([
            'registration_status' => 'confirmed', 'publication_status' => 'public',
            'default_sale_price' => 100, 'shortage_policy' => 'reject',
        ]);
        $lot = InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 10]);

        return compact('customer', 'product', 'lot');
    }

    private function checkoutInput(): array
    {
        return [
            'notes' => null, 'payment_method' => 'bank_transfer', 'shipping_name' => '購入者',
            'shipping_postal_code' => '100-0001', 'shipping_prefecture' => '東京都',
            'shipping_address_line1' => '千代田区1-1', 'shipping_address_line2' => null,
        ];
    }

    public function test_checkout_saves_encrypted_snapshot_and_displays_it_on_completion_and_admin_detail(): void
    {
        $context = $this->context();
        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput())->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(BankTransfer::details(), $order->bank_transfer_snapshot);
        $raw = DB::table('orders')->where('id', $order->id)->value('bank_transfer_snapshot');
        $this->assertStringNotContainsString('001234567890', $raw);
        $this->get('/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->component('Orders/Show')
            ->where('order.bank_transfer_snapshot', BankTransfer::details()));
        $this->get('/orders')->assertInertia(fn (Assert $page) => $page->missing('orders.data.0.bank_transfer_snapshot'));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/orders/'.$order->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Orders/Form')->where('order.bank_transfer_snapshot', BankTransfer::details()));
    }

    public function test_cod_fee_configuration_is_not_applied_to_bank_transfer_orders(): void
    {
        $context = $this->context();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])
            ->assertSessionHasNoErrors();
        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '2']])
            ->post('/checkout', $this->checkoutInput())->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('bank_transfer', $order->payment_method);
        $this->assertSame('200.0000', $order->subtotal);
        $this->assertSame('0.0000', $order->tax_amount);
        $this->assertSame('200.0000', $order->total);
        $this->assertSame('330.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', [...$this->checkoutInput(), 'payment_method' => 'cash_on_delivery'])
            ->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unregistered_bank_blocks_confirmation_and_keeps_cart_stock_and_database_unchanged(): void
    {
        $context = $this->context(false);
        $cart = [$context['product']->id => '1'];
        $this->actingAs($context['customer'])->withSession(['cart' => $cart])
            ->post('/checkout', $this->checkoutInput())->assertSessionHasErrors([
                'payment_method' => '振込先が設定されていないため、銀行振込の注文を確定できません。管理者へお問い合わせください。',
            ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_lines', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('10.0000', $context['lot']->fresh()->current_quantity);
        $this->assertSame($cart, session('cart'));
    }

    public function test_settings_changes_do_not_change_existing_order_snapshot(): void
    {
        $context = $this->context();
        $original = BankTransfer::details();
        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput())->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details([
            'bank_name' => '新銀行', 'account_number' => '987654321',
        ]))->assertSessionHasNoErrors();
        $this->assertSame($original, $order->fresh()->bank_transfer_snapshot);
        $this->actingAs($context['customer'])->get('/orders/'.$order->id)
            ->assertInertia(fn (Assert $page) => $page->where('order.bank_transfer_snapshot', $original));
        $this->assertSame('新銀行', OrderSetting::current()->bank_transfer['bank_name']);
    }

    public function test_draft_edits_and_confirmation_never_overwrite_an_existing_snapshot(): void
    {
        $context = $this->context();
        $original = BankTransfer::details();
        $order = Order::factory()->create(['customer_id' => $context['customer']->id, 'payment_method' => 'bank_transfer']);
        $order->bank_transfer_snapshot = $original;
        $order->save();
        $line = OrderLine::factory()->create([
            'order_id' => $order->id, 'product_id' => $context['product']->id, 'quantity' => 1, 'unit_sale_price' => 100, 'line_amount' => 100,
        ]);
        BankTransfer::configure(['bank_name' => '新銀行']);
        $input = [
            'customer_id' => $context['customer']->id, 'ordered_on' => '2026-11-16', 'lock_version' => 0,
            'action' => 'draft', 'bank_transfer_snapshot' => ['bank_name' => '不正な上書き'],
            'lines' => [['id' => $line->id, 'product_id' => $line->product_id, 'quantity' => 1, 'unit_sale_price' => 100]],
        ];
        $this->actingAs(User::factory()->admin()->create())->put('/admin/orders/'.$order->id, $input)->assertSessionHasNoErrors();
        $this->assertSame($original, $order->fresh()->bank_transfer_snapshot);
        $input['action'] = 'confirm';
        $input['lock_version'] = $order->fresh()->lock_version;
        $this->put('/admin/orders/'.$order->id, $input)->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($original, $order->fresh()->bank_transfer_snapshot);
    }

    public function test_legacy_order_has_no_current_bank_fallback_or_backfill(): void
    {
        $context = $this->context();
        $order = Order::factory()->create(['customer_id' => $context['customer']->id, 'payment_method' => 'bank_transfer', 'status' => 'confirmed']);
        $this->actingAs($context['customer'])->get('/orders/'.$order->id)
            ->assertInertia(fn (Assert $page) => $page->where('order.bank_transfer_snapshot', null))
            ->assertDontSee('001234567890');
        $this->actingAs(User::factory()->admin()->create())->get('/admin/orders/'.$order->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('order.bank_transfer_snapshot', null));
        $this->assertNull($order->fresh()->bank_transfer_snapshot);
    }

    public function test_other_customers_cannot_view_order_bank_and_non_bank_orders_do_not_expose_snapshot(): void
    {
        $context = $this->context();
        $order = Order::factory()->create(['customer_id' => $context['customer']->id, 'payment_method' => 'cash', 'status' => 'confirmed']);
        $order->bank_transfer_snapshot = BankTransfer::details();
        $order->save();
        $this->actingAs(User::factory()->create())->get('/orders/'.$order->id)->assertNotFound()->assertDontSee('001234567890');
        $this->actingAs($context['customer'])->get('/orders/'.$order->id)
            ->assertInertia(fn (Assert $page) => $page->missing('order.bank_transfer_snapshot'));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/orders/'.$order->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->missing('order.bank_transfer_snapshot'));
    }

    public function test_bank_check_is_in_common_confirmation_action_and_does_not_apply_to_other_payments(): void
    {
        $actor = User::factory()->admin()->create();
        $bankOrder = Order::factory()->create(['payment_method' => 'bank_transfer']);
        try {
            app(ConfirmOrder::class)->handle($bankOrder, $actor);
            $this->fail('共通の注文確定処理で振込先を検証する必要があります。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }
        $this->assertSame('draft', $bankOrder->fresh()->status);
        $otherOrder = Order::factory()->create(['payment_method' => 'cash']);
        app(ConfirmOrder::class)->handle($otherOrder, $actor);
        $this->assertSame('confirmed', $otherOrder->fresh()->status);
        $this->assertNull($otherOrder->fresh()->bank_transfer_snapshot);
    }
}
