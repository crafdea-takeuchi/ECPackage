<?php

namespace Tests\Feature;

use App\Actions\Orders\ShippingZones;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\BankTransfer;
use Tests\TestCase;

class ShippingSettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fees(): array
    {
        return ['hokkaido' => 1500, 'tohoku' => 1000, 'kanto_shinetsu' => 800, 'hokuriku_chubu' => 900,
            'kansai_chugoku' => 1000, 'shikoku' => 1100, 'kyushu' => 1200, 'okinawa' => 1800];
    }

    private function thresholds(): array
    {
        return ['hokkaido' => 20000, 'tohoku' => 10000, 'kanto_shinetsu' => 10000, 'hokuriku_chubu' => 10000,
            'kansai_chugoku' => 10000, 'shikoku' => 10000, 'kyushu' => 15000, 'okinawa' => 30000];
    }

    private function savedThresholds(): array
    {
        return ['hokkaido' => '20000.0000', 'tohoku' => '10000.0000', 'kanto_shinetsu' => '10000.0000', 'hokuriku_chubu' => '10000.0000',
            'kansai_chugoku' => '10000.0000', 'shikoku' => '10000.0000', 'kyushu' => '15000.0000', 'okinawa' => '30000.0000'];
    }

    private function input(array $overrides = []): array
    {
        return array_replace_recursive(['shipping_zone_fees' => $this->fees(), 'free_shipping_thresholds' => $this->thresholds()], $overrides);
    }

    private function savedFees(): array
    {
        return ['hokkaido' => '1500.0000', 'tohoku' => '1000.0000', 'kanto_shinetsu' => '800.0000', 'hokuriku_chubu' => '900.0000',
            'kansai_chugoku' => '1000.0000', 'shikoku' => '1100.0000', 'kyushu' => '1200.0000', 'okinawa' => '1800.0000'];
    }

    public function test_hub_preserves_bank_and_fee_cards_and_links_to_unconfigured_shipping(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.index'))
            ->assertInertia(fn (Assert $page) => $page->has('cards', 3)
                ->where('cards.0.title', '振込先設定')->where('cards.1.title', '手数料設定')
                ->where('cards.2.title', '送料設定')->where('cards.2.status', '送料が設定されていません')
                ->where('cards.2.href', route('admin.settings.shipping.edit', [], false)));
        $this->get(route('admin.settings.shipping.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Shipping')->has('zones', 8)
            ->where('shippingZoneFees', null)->where('freeShippingThresholds', null)
            ->where('saveUrl', route('admin.settings.shipping.update', [], false))
            ->where('settingsUrl', route('admin.settings.index', [], false)));
    }

    public function test_all_47_existing_prefectures_belong_to_exactly_one_zone_with_required_boundaries(): void
    {
        $zones = ShippingZones::definitions();
        $this->assertSame(['hokkaido', 'tohoku', 'kanto_shinetsu', 'hokuriku_chubu', 'kansai_chugoku', 'shikoku', 'kyushu', 'okinawa'], array_column($zones, 'code'));
        $all = array_merge(...array_column($zones, 'prefectures'));
        $this->assertCount(47, $all);
        $this->assertCount(47, array_unique($all));
        $this->assertEqualsCanonicalizing(Order::PREFECTURES, $all);
        $this->assertSame([1, 6, 9, 8, 11, 4, 7, 1], array_map(fn (array $zone): int => count($zone['prefectures']), $zones));
        $map = array_column($zones, 'prefectures', 'code');
        $this->assertSame(['北海道'], $map['hokkaido']);
        $this->assertSame(['沖縄県'], $map['okinawa']);
        $this->assertContains('新潟県', $map['hokuriku_chubu']);
        $this->assertNotContains('新潟県', $map['kanto_shinetsu']);
        foreach (['山梨県', '長野県'] as $prefecture) {
            $this->assertContains($prefecture, $map['kanto_shinetsu']);
            $this->assertNotContains($prefecture, $map['hokuriku_chubu']);
        }
    }

    public function test_shipping_and_threshold_are_saved_updated_and_displayed_on_one_row(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $this->input())
            ->assertRedirect(route('admin.settings.shipping.edit'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', '送料設定を保存しました。');
        $this->assertSame($this->savedFees(), OrderSetting::current()->shipping_zone_fees);
        $this->assertSame($this->savedThresholds(), OrderSetting::current()->free_shipping_thresholds);
        $this->get(route('admin.settings.shipping.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('shippingZoneFees', $this->savedFees())->where('freeShippingThresholds', $this->savedThresholds()));
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.status', "8地域設定済み\n地域別送料無料基準：8地域設定済み"));
        $update = $this->input(['shipping_zone_fees' => ['hokkaido' => 1600]]);
        $this->put(route('admin.settings.shipping.update'), $update)->assertSessionHasNoErrors();
        $this->put(route('admin.settings.shipping.update'), $update)->assertSessionHasNoErrors();
        $this->assertSame('1600.0000', OrderSetting::current()->shipping_zone_fees['hokkaido']);
        $this->assertDatabaseCount('order_settings', 1);
    }

    public function test_zero_fees_and_zero_threshold_are_valid_and_not_unconfigured(): void
    {
        $fees = array_fill_keys(ShippingZones::codes(), 0);
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), [
            'shipping_zone_fees' => $fees, 'free_shipping_thresholds' => array_fill_keys(ShippingZones::codes(), 0),
        ])->assertSessionHasNoErrors();
        $this->assertSame(array_fill_keys(ShippingZones::codes(), '0.0000'), OrderSetting::current()->shipping_zone_fees);
        $this->assertSame(array_fill_keys(ShippingZones::codes(), '0.0000'), OrderSetting::current()->free_shipping_thresholds);
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.status', "8地域設定済み\n地域別送料無料基準：8地域設定済み")->where('cards.2.warning', false));
    }

    public function test_regional_thresholds_can_be_cleared_and_unsent_regions_are_preserved(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $this->input())->assertSessionHasNoErrors();
        $this->assertSame('20000.0000', OrderSetting::current()->free_shipping_thresholds['hokkaido']);
        $this->assertSame('30000.0000', OrderSetting::current()->free_shipping_thresholds['okinawa']);
        $this->put(route('admin.settings.shipping.update'), [
            'shipping_zone_fees' => $this->fees(), 'free_shipping_thresholds' => ['hokkaido' => '', 'tohoku' => 0],
        ])->assertSessionHasNoErrors();
        $expected = [...$this->savedThresholds(), 'hokkaido' => null, 'tohoku' => '0.0000'];
        $this->assertSame($expected, OrderSetting::current()->free_shipping_thresholds);
        $this->assertSame($this->savedFees(), OrderSetting::current()->shipping_zone_fees);
        $this->get(route('admin.settings.shipping.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('freeShippingThresholds.hokkaido', null)->where('freeShippingThresholds.tohoku', '0.0000')
            ->where('freeShippingThresholds.okinawa', '30000.0000'));
        $fees = [...$this->fees(), 'hokkaido' => 1600];
        $this->put(route('admin.settings.shipping.update'), ['shipping_zone_fees' => $fees])->assertSessionHasNoErrors();
        $this->assertSame($expected, OrderSetting::current()->free_shipping_thresholds);
        $this->put(route('admin.settings.shipping.update'), [
            'shipping_zone_fees' => $fees, 'free_shipping_thresholds' => ['okinawa' => 25000],
        ])->assertSessionHasNoErrors();
        $this->assertSame([...$expected, 'okinawa' => '25000.0000'], OrderSetting::current()->free_shipping_thresholds);
        $this->assertSame([...$this->savedFees(), 'hokkaido' => '1600.0000'], OrderSetting::current()->shipping_zone_fees);
    }

    public function test_all_regional_thresholds_can_be_blank_without_becoming_zero(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $this->input([
            'free_shipping_thresholds' => array_fill_keys(ShippingZones::codes(), ''),
        ]))->assertSessionHasNoErrors();
        $this->assertSame(array_fill_keys(ShippingZones::codes(), null), OrderSetting::current()->free_shipping_thresholds);
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.status', "8地域設定済み\n地域別送料無料基準：設定なし"));
    }

    #[TestWith(['shipping_zone_fees.hokkaido', -1])]
    #[TestWith(['shipping_zone_fees.tohoku', '1000.5'])]
    #[TestWith(['shipping_zone_fees.kanto_shinetsu', 'abc'])]
    #[TestWith(['shipping_zone_fees.hokuriku_chubu', 1000000000000000])]
    #[TestWith(['shipping_zone_fees.kansai_chugoku', null])]
    #[TestWith(['shipping_zone_fees.shikoku', '  '])]
    #[TestWith(['shipping_zone_fees.kyushu', [1200]])]
    #[TestWith(['shipping_zone_fees.okinawa', '1e3'])]
    #[TestWith(['free_shipping_thresholds.hokkaido', -1])]
    #[TestWith(['free_shipping_thresholds.okinawa', '100.5'])]
    #[TestWith(['free_shipping_thresholds.kanto_shinetsu', 'abc'])]
    #[TestWith(['free_shipping_thresholds.kyushu', '999999999999999999999999'])]
    public function test_invalid_amount_rejects_whole_update_without_losing_saved_configuration(string $field, mixed $value): void
    {
        $settings = OrderSetting::current();
        $settings->shipping_zone_fees = $this->savedFees();
        $settings->free_shipping_thresholds = $this->savedThresholds();
        $settings->save();
        $input = $this->input();
        data_set($input, $field, $value);
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $input)
            ->assertSessionHasErrors($field);
        $this->assertSame($this->savedFees(), OrderSetting::current()->shipping_zone_fees);
        $this->assertSame($this->savedThresholds(), OrderSetting::current()->free_shipping_thresholds);
    }

    public function test_missing_and_unknown_regions_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $missing = $this->input();
        unset($missing['shipping_zone_fees']['okinawa']);
        $this->put(route('admin.settings.shipping.update'), $missing)->assertSessionHasErrors('shipping_zone_fees.okinawa');
        $this->put(route('admin.settings.shipping.update'), $this->input(['shipping_zone_fees' => ['islands' => 300]]))->assertSessionHasErrors('shipping_zone_fees');
        $this->put(route('admin.settings.shipping.update'), [])->assertSessionHasErrors('shipping_zone_fees');
        $this->put(route('admin.settings.shipping.update'), $this->input(['free_shipping_thresholds' => ['islands' => 500]]))->assertSessionHasErrors('free_shipping_thresholds');
        $this->assertNull(OrderSetting::current()->shipping_zone_fees);
    }

    public function test_existing_amount_upper_bound_is_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), [
            'shipping_zone_fees' => array_fill_keys(ShippingZones::codes(), 999999999999999),
            'free_shipping_thresholds' => array_fill_keys(ShippingZones::codes(), 999999999999999),
        ])->assertSessionHasNoErrors();
        $this->assertSame('999999999999999.0000', OrderSetting::current()->shipping_zone_fees['hokkaido']);
        $this->assertSame('999999999999999.0000', OrderSetting::current()->free_shipping_thresholds['hokkaido']);
    }

    public function test_shipping_bank_fee_and_order_settings_preserve_each_others_values(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $bank = BankTransfer::configure();
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])->assertSessionHasNoErrors();
        $this->put(route('admin.order-settings.update'), ['cancellable_statuses' => ['draft']])->assertSessionHasNoErrors();
        $this->put(route('admin.settings.shipping.update'), [
            ...$this->input(), 'bank_transfer' => null, 'cash_on_delivery_fee' => 0, 'cancellable_statuses' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame($bank, OrderSetting::current()->bank_transfer);
        $this->assertSame('330.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->assertSame(['draft'], OrderSetting::current()->cancellable_statuses);
        $bank['branch_name'] = '変更支店';
        $this->put(route('admin.settings.bank-transfer.update'), $bank)->assertSessionHasNoErrors();
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 550])->assertSessionHasNoErrors();
        $this->put(route('admin.order-settings.update'), ['cancellable_statuses' => ['draft', 'confirmed']])->assertSessionHasNoErrors();
        $this->assertSame($this->savedFees(), OrderSetting::current()->shipping_zone_fees);
        $this->assertSame($this->savedThresholds(), OrderSetting::current()->free_shipping_thresholds);
        $this->put(route('admin.settings.shipping.update'), $this->input(['free_shipping_thresholds' => ['hokkaido' => 5000]]))->assertSessionHasNoErrors();
        $this->assertSame($bank, OrderSetting::current()->bank_transfer);
        $this->assertSame('550.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->assertSame(['draft', 'confirmed'], OrderSetting::current()->cancellable_statuses);
        $this->assertDatabaseCount('order_settings', 1);
    }

    public function test_permissions_follow_existing_verified_admin_settings_routes(): void
    {
        $this->get(route('admin.settings.shipping.edit'))->assertRedirect('/admin/login');
        $this->put(route('admin.settings.shipping.update'), $this->input())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings.shipping.edit'))->assertForbidden();
        $this->put(route('admin.settings.shipping.update'), $this->input())->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get(route('admin.settings.shipping.edit'))->assertRedirect('/email/verify');
        $this->put(route('admin.settings.shipping.update'), $this->input())->assertRedirect('/email/verify');
        $this->assertNull(OrderSetting::current()->shipping_zone_fees);
    }

    public function test_absent_settings_row_is_created_only_at_id_one(): void
    {
        OrderSetting::whereKey(1)->delete();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $this->input())->assertSessionHasNoErrors();
        $this->assertSame(1, OrderSetting::sole()->id);
        $this->assertDatabaseCount('order_settings', 1);
    }

    public function test_shipping_configuration_is_not_applied_to_checkout_or_bank_transfer_order(): void
    {
        BankTransfer::configure();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.shipping.update'), $this->input(['free_shipping_thresholds' => array_fill_keys(ShippingZones::codes(), 0)]))->assertSessionHasNoErrors();
        $product = Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 100]);
        InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 10]);
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$product->id => '2']])->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page->where('subtotal', '200.0000')->where('total', '200.0000')
                ->missing('shippingFee')->missing('shippingZoneFees')->missing('freeShippingThresholds'));
        $this->post('/checkout', [
            'notes' => null, 'payment_method' => 'bank_transfer', 'shipping_name' => '購入者', 'shipping_postal_code' => '100-0001',
            'shipping_prefecture' => '東京都', 'shipping_address_line1' => '千代田区1-1', 'shipping_address_line2' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame('200.0000', Order::sole()->total);
        $this->assertSame('200.0000', Order::sole()->subtotal);
        $this->assertSame('0.0000', Order::sole()->tax_amount);
    }
}
