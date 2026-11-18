<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashOnDeliveryOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{customer: User, product: Product}
     */
    private function context(): array
    {
        $taxRate = TaxRate::factory()->create(['name' => '標準税率', 'rate' => '10.00']);
        $product = Product::factory()->create([
            'registration_status' => 'confirmed', 'publication_status' => 'public',
            'default_sale_price' => 100, 'shortage_policy' => 'reject', 'tax_rate_id' => $taxRate->id,
        ]);
        InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 10]);

        return ['customer' => User::factory()->create(), 'product' => $product];
    }

    private function configureFee(?int $fee): void
    {
        $settings = OrderSetting::current();
        $settings->cash_on_delivery_fee = $fee;
        $settings->save();
    }

    /**
     * @return array<string, string|null>
     */
    private function checkoutInput(): array
    {
        return [
            'notes' => null, 'payment_method' => 'cash_on_delivery', 'shipping_name' => '購入者',
            'shipping_postal_code' => '100-0001', 'shipping_prefecture' => '東京都',
            'shipping_address_line1' => '千代田区1-1', 'shipping_address_line2' => null,
        ];
    }

    public function test_checkout_page_offers_cash_on_delivery_with_current_fee(): void
    {
        $context = $this->context();
        $this->configureFee(330);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '2']])
            ->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page->component('Checkout/Create')
                ->where('paymentMethods.cash_on_delivery', '代引き')
                ->where('paymentFees.cash_on_delivery', '330.0000')
                ->where('total', '220.0000'));
    }

    public function test_cash_on_delivery_order_adds_fee_to_total_without_changing_tax(): void
    {
        $context = $this->context();
        $this->configureFee(330);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '2']])
            ->post('/checkout', $this->checkoutInput())
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('cash_on_delivery', $order->payment_method);
        $this->assertSame('200.0000', $order->subtotal);
        $this->assertSame('20.0000', $order->tax_amount);
        $this->assertSame('330.0000', $order->payment_fee);
        $this->assertSame('550.0000', $order->total);
        $this->assertNull($order->bank_transfer_snapshot);

        $this->get('/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->component('Orders/Show')
            ->where('order.payment_fee', '330.0000')->where('order.total', '550.0000')
            ->missing('order.bank_transfer_snapshot'));
    }

    public function test_unset_fee_allows_cash_on_delivery_without_fee(): void
    {
        $context = $this->context();
        $this->configureFee(null);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput())
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('0.0000', $order->payment_fee);
        $this->assertSame('110.0000', $order->total);
    }

    public function test_fee_changes_after_ordering_do_not_change_existing_order(): void
    {
        $context = $this->context();
        $this->configureFee(330);
        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput())
            ->assertSessionHasNoErrors();

        $this->configureFee(550);

        $order = Order::sole();
        $this->assertSame('330.0000', $order->payment_fee);
        $this->assertSame('440.0000', $order->total);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/orders/'.$order->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Orders/Form')
                ->where('order.payment_fee', '330.0000')
                ->has('taxes', 1));
    }
}
