<?php

namespace Tests\Feature;

use App\Actions\Orders\ShippingZones;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderSetting;
use App\Models\PostalCodeShippingGroup;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShippingFeeOrderTest extends TestCase
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
            'default_sale_price' => 1000, 'shortage_policy' => 'reject', 'tax_rate_id' => $taxRate->id,
        ]);
        InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 100]);

        return ['customer' => User::factory()->create(), 'product' => $product];
    }

    /**
     * @param  array<string, string|null>|null  $thresholds
     */
    private function configureShipping(?array $fees, ?array $thresholds = null, ?int $codFee = null): void
    {
        $settings = OrderSetting::current();
        $settings->shipping_zone_fees = $fees;
        $settings->free_shipping_thresholds = $thresholds;
        $settings->cash_on_delivery_fee = $codFee;
        $settings->save();
    }

    /**
     * @return array<string, string>
     */
    private function zoneFees(): array
    {
        return [
            'hokkaido' => '1200.0000', 'tohoku' => '900.0000', 'kanto_shinetsu' => '800.0000', 'hokuriku_chubu' => '850.0000',
            'kansai_chugoku' => '950.0000', 'shikoku' => '1000.0000', 'kyushu' => '1100.0000', 'okinawa' => '1500.0000',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function checkoutInput(string $prefecture): array
    {
        return [
            'notes' => null, 'payment_method' => 'cash_on_delivery', 'shipping_name' => '購入者',
            'shipping_postal_code' => '100-0001', 'shipping_prefecture' => $prefecture,
            'shipping_address_line1' => '1-1', 'shipping_address_line2' => null,
        ];
    }

    #[TestWith(['北海道', 'hokkaido'])]
    #[TestWith(['東京都', 'kanto_shinetsu'])]
    #[TestWith(['長野県', 'kanto_shinetsu'])]
    #[TestWith(['新潟県', 'hokuriku_chubu'])]
    #[TestWith(['三重県', 'hokuriku_chubu'])]
    #[TestWith(['鳥取県', 'kansai_chugoku'])]
    #[TestWith(['沖縄県', 'okinawa'])]
    #[TestWith(['存在しない県', null])]
    public function test_prefecture_is_mapped_to_the_zone_shown_on_the_settings_screen(string $prefecture, ?string $zone): void
    {
        $this->assertSame($zone, ShippingZones::zoneForPrefecture($prefecture));
    }

    public function test_checkout_page_provides_shipping_fee_for_every_prefecture(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees(), ['okinawa' => '2000.0000']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '2']])
            ->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page->component('Checkout/Create')
                ->has('shippingFees', 47)
                ->where('shippingFees.北海道', '1200.0000')
                ->where('shippingFees.東京都', '800.0000')
                ->where('shippingFees.沖縄県', '0.0000'));
    }

    public function test_order_adds_shipping_fee_of_the_destination_zone_and_cod_fee(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees(), null, 330);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput('北海道'))
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('1000.0000', $order->subtotal);
        $this->assertSame('100.0000', $order->tax_amount);
        $this->assertSame('1200.0000', $order->shipping_fee);
        $this->assertSame('330.0000', $order->payment_fee);
        $this->assertSame('2630.0000', $order->total);

        $this->get('/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page
            ->where('order.shipping_fee', '1200.0000')->where('order.total', '2630.0000'));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/orders/'.$order->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('order.shipping_fee', '1200.0000')->has('taxes', 1));
    }

    #[TestWith(['2', '2200.0000', '0.0000'])]
    #[TestWith(['1', '1100.0000', '800.0000'])]
    public function test_free_shipping_applies_when_tax_included_items_total_reaches_threshold(string $quantity, string $itemsTotal, string $expectedFee): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees(), ['kanto_shinetsu' => '2200.0000']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => $quantity]])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame($expectedFee, $order->shipping_fee);
        $this->assertSame(bcadd($itemsTotal, $expectedFee, 4), $order->total);
    }

    public function test_unset_shipping_fee_allows_ordering_without_shipping_fee(): void
    {
        $context = $this->context();
        $this->configureShipping(null);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('0.0000', $order->shipping_fee);
        $this->assertSame('1100.0000', $order->total);
    }

    private function postalCodeGroup(string $fee, ?string $threshold, array $postalCodes): PostalCodeShippingGroup
    {
        $group = PostalCodeShippingGroup::create(['name' => '離島'.$fee, 'shipping_fee' => $fee, 'free_shipping_threshold' => $threshold]);
        foreach ($postalCodes as $postalCode) {
            $group->postalCodes()->create(['postal_code' => $postalCode]);
        }

        return $group;
    }

    public function test_postal_code_group_fee_takes_priority_over_prefecture_fee(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees(), ['kanto_shinetsu' => '1000.0000']);
        $this->postalCodeGroup('3000.0000', null, ['1000001']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('3000.0000', $order->shipping_fee, '県別の送料無料基準額（1,000円）は適用されない');
        $this->assertSame('4100.0000', $order->total);
    }

    #[TestWith(['2', '0.0000'])]
    #[TestWith(['1', '3000.0000'])]
    public function test_postal_code_group_uses_its_own_free_shipping_threshold(string $quantity, string $expectedFee): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees());
        $this->postalCodeGroup('3000.0000', '2200.0000', ['1000001']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => $quantity]])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $this->assertSame($expectedFee, Order::sole()->shipping_fee);
    }

    public function test_postal_code_outside_groups_falls_back_to_prefecture_fee(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees());
        $this->postalCodeGroup('3000.0000', null, ['9071801']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $this->assertSame('800.0000', Order::sole()->shipping_fee);
    }

    public function test_checkout_page_provides_fees_for_group_postal_codes(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees());
        $this->postalCodeGroup('3000.0000', '2000.0000', ['9071801', '9071800']);
        $this->postalCodeGroup('500.0000', null, ['1000001']);

        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '2']])
            ->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page
                ->has('postalCodeShippingFees', 3)
                ->where('postalCodeShippingFees.9071801', '0.0000')
                ->where('postalCodeShippingFees.9071800', '0.0000')
                ->where('postalCodeShippingFees.1000001', '500.0000')
                ->where('shippingFees.東京都', '800.0000'));
    }

    public function test_settings_changes_after_ordering_do_not_change_existing_shipping_fee(): void
    {
        $context = $this->context();
        $this->configureShipping($this->zoneFees());
        $this->actingAs($context['customer'])->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput('東京都'))
            ->assertSessionHasNoErrors();

        $this->configureShipping([...$this->zoneFees(), 'kanto_shinetsu' => '5000.0000']);

        $order = Order::sole();
        $this->assertSame('800.0000', $order->shipping_fee);
        $this->assertSame('1900.0000', $order->total);
    }
}
