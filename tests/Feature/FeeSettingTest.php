<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\BankTransfer;
use Tests\TestCase;

class FeeSettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_hub_has_bank_and_fee_cards_and_fee_form_initially_shows_unset(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->has('cards', 5)->where('cards.0.title', '振込先設定')->where('cards.1.title', '手数料設定')
            ->where('cards.1.description', '代引きなど、支払方法に応じた手数料を設定します。')
            ->where('cards.1.status', '現在の代引き手数料：未設定')
            ->where('cards.1.href', route('admin.settings.fees.edit', [], false)));
        $this->get(route('admin.settings.fees.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Fees')->where('cashOnDeliveryFee', null)
            ->where('cashOnDeliveryAvailable', true)
            ->where('saveUrl', route('admin.settings.fees.update', [], false))
            ->where('settingsUrl', route('admin.settings.index', [], false)));
    }

    #[TestWith([330, '330.0000', '現在の代引き手数料：330円'])]
    #[TestWith([0, '0.0000', '現在の代引き手数料：0円（無料）'])]
    #[TestWith([1234, '1234.0000', '現在の代引き手数料：1,234円'])]
    #[TestWith([999999999999999, '999999999999999.0000', '現在の代引き手数料：999,999,999,999,999円'])]
    public function test_fee_is_saved_on_single_row_and_displayed(int $fee, string $saved, string $status): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => $fee])
            ->assertRedirect(route('admin.settings.fees.edit'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', '手数料設定を保存しました。');
        $this->assertSame($saved, OrderSetting::current()->cash_on_delivery_fee);
        $this->assertDatabaseCount('order_settings', 1);
        $this->get(route('admin.settings.fees.edit'))->assertInertia(fn (Assert $page) => $page->where('cashOnDeliveryFee', $saved));
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page->where('cards.1.status', $status));
    }

    public function test_update_repeated_submit_and_clear_do_not_create_duplicate_rows(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([330, 550, 550] as $fee) {
            $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => $fee])->assertSessionHasNoErrors();
        }
        $this->assertSame('550.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->assertDatabaseCount('order_settings', 1);
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => '  '])->assertSessionHasNoErrors();
        $this->assertNull(OrderSetting::current()->cash_on_delivery_fee);
        $this->assertDatabaseCount('order_settings', 1);
    }

    #[TestWith([-1])]
    #[TestWith(['abc'])]
    #[TestWith(['330.5'])]
    #[TestWith(['1e3'])]
    #[TestWith([1000000000000000])]
    #[TestWith(['99999999999999999999999999'])]
    #[TestWith([[330]])]
    public function test_invalid_amount_is_rejected_and_existing_value_is_preserved(mixed $fee): void
    {
        $settings = OrderSetting::current();
        $settings->cash_on_delivery_fee = 330;
        $settings->save();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => $fee])
            ->assertSessionHasErrors('cash_on_delivery_fee');
        $this->assertSame('330.0000', OrderSetting::current()->cash_on_delivery_fee);
    }

    public function test_missing_field_does_not_implicitly_clear_saved_fee(): void
    {
        $settings = OrderSetting::current();
        $settings->cash_on_delivery_fee = 330;
        $settings->save();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.fees.update'), [])->assertSessionHasErrors('cash_on_delivery_fee');
        $this->assertSame('330.0000', OrderSetting::current()->cash_on_delivery_fee);
    }

    public function test_all_three_setting_screens_preserve_each_others_fields(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $bank = BankTransfer::configure();
        $this->put(route('admin.settings.fees.update'), [
            'cash_on_delivery_fee' => 330, 'bank_transfer' => null, 'cancellable_statuses' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame($bank, OrderSetting::current()->bank_transfer);
        $this->assertSame(['draft', 'confirmed', 'preparing'], OrderSetting::current()->cancellable_statuses);
        $bank['bank_name'] = '変更銀行';
        $this->put(route('admin.settings.bank-transfer.update'), $bank)->assertSessionHasNoErrors();
        $bankUpdatedAt = OrderSetting::current()->bank_transfer_updated_at->format('Y-m-d H:i:s.u');
        $this->assertSame('330.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 550])->assertSessionHasNoErrors();
        $this->assertSame($bank, OrderSetting::current()->bank_transfer);
        $this->assertSame($bankUpdatedAt, OrderSetting::current()->bank_transfer_updated_at->format('Y-m-d H:i:s.u'));
        $this->put(route('admin.order-settings.update'), ['cancellable_statuses' => ['draft']])->assertSessionHasNoErrors();
        $this->assertSame($bank, OrderSetting::current()->bank_transfer);
        $this->assertSame('550.0000', OrderSetting::current()->cash_on_delivery_fee);
        $this->assertSame(['draft'], OrderSetting::current()->cancellable_statuses);
        $this->assertDatabaseCount('order_settings', 1);
    }

    public function test_admin_authentication_and_email_verification_are_required_for_get_and_put(): void
    {
        $this->get(route('admin.settings.fees.edit'))->assertRedirect('/admin/login');
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings.fees.edit'))->assertForbidden();
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get(route('admin.settings.fees.edit'))->assertRedirect('/email/verify');
        $this->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])->assertRedirect('/email/verify');
        $this->assertNull(OrderSetting::current()->cash_on_delivery_fee);
    }

    public function test_absent_singleton_is_created_at_id_one_and_cod_is_available(): void
    {
        OrderSetting::whereKey(1)->delete();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.fees.update'), ['cash_on_delivery_fee' => 330])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, OrderSetting::sole()->id);
        $this->assertSame('330.0000', OrderSetting::sole()->cash_on_delivery_fee);
        $this->assertSame(['bank_transfer' => '銀行振込', 'cash_on_delivery' => '代引き'], Order::PAYMENT_METHODS);
        $this->assertTrue(Schema::hasColumn('orders', 'payment_fee'));
    }
}
