<?php

namespace Tests\Feature;

use App\Models\OrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\BankTransfer;
use Tests\TestCase;

class BankTransferSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_settings_hub_only_exposes_bank_card_and_preserves_existing_order_settings_route(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Settings/Index')
                ->has('cards', 1)
                ->where('cards.0.title', '振込先設定')
                ->where('cards.0.description', '銀行振込でお客様へ案内する振込先を登録します。')
                ->where('cards.0.href', route('admin.settings.bank-transfer.edit', [], false))
                ->where('cards.0.account_number', null)
                ->where('cards.0.warning', true)
                ->where('cards.0.status', '振込先が設定されていません。銀行振込の注文受付前に登録してください。')
                ->where('adminSettingsUrl', route('admin.settings.index', [], false)));
        $this->assertTrue(Route::has('admin.order-settings.edit'));
        $this->assertTrue(Route::has('admin.order-settings.update'));
        $this->get(route('admin.order-settings.edit'))->assertOk();
    }

    public function test_bank_form_initially_shows_unregistered_state(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.bank-transfer.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Settings/BankTransfer')
                ->where('bank', null)->where('registered', false)
                ->where('saveUrl', route('admin.settings.bank-transfer.update', [], false))
                ->where('accountTypes', ['普通', '当座', '貯蓄']));
    }

    public function test_register_update_and_repeated_submit_keep_one_settings_row_and_existing_order_settings(): void
    {
        $actor = User::factory()->admin()->create();
        OrderSetting::current()->update(['cancellable_statuses' => ['draft']]);
        $this->actingAs($actor);
        $first = BankTransfer::details();
        $this->put(route('admin.settings.bank-transfer.update'), $first)
            ->assertRedirect(route('admin.settings.bank-transfer.edit'))->assertSessionHasNoErrors()->assertSessionHas('status', '振込先を保存しました。');
        $this->assertSame($first, OrderSetting::current()->bank_transfer);
        $this->assertSame($actor->id, (int) OrderSetting::current()->bank_transfer_updated_by);
        $this->assertNotNull(OrderSetting::current()->bank_transfer_updated_at);
        $second = BankTransfer::details(['bank_name' => '変更銀行', 'account_type' => '当座', 'account_number' => '000000987654321']);
        $this->put(route('admin.settings.bank-transfer.update'), $second)->assertSessionHasNoErrors();
        $this->put(route('admin.settings.bank-transfer.update'), $second)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('order_settings', 1);
        $this->assertSame($second, OrderSetting::current()->bank_transfer);
        $this->assertSame(['draft'], OrderSetting::current()->cancellable_statuses);
        $this->get(route('admin.settings.bank-transfer.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('registered', true)->where('bank.account_number', '000000987654321'));
        $this->get(route('admin.order-settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.cancellable_statuses', ['draft'])->missing('settings.bank_transfer'));
        $this->put(route('admin.order-settings.update'), ['cancellable_statuses' => ['draft', 'confirmed']])->assertSessionHasNoErrors();
        $this->assertSame($second, OrderSetting::current()->bank_transfer);
    }

    public function test_missing_singleton_is_recreated_at_fixed_id_one(): void
    {
        OrderSetting::whereKey(1)->delete();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details())
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.bank-transfer.edit'));
        $this->assertDatabaseCount('order_settings', 1);
        $this->assertSame(1, OrderSetting::sole()->id);
        $this->assertSame(BankTransfer::details(), OrderSetting::sole()->bank_transfer);
    }

    public function test_settings_summary_masks_account_and_never_contains_full_bank_details(): void
    {
        BankTransfer::configure();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cards.0.account_number', '****890')
                ->where('cards.0.status', '振込先設定済み：テスト銀行 テスト支店')->where('cards.0.warning', false))
            ->assertDontSee('001234567890')->assertDontSee('注文番号を振込名義');
    }

    public function test_required_fields_are_validated_without_saving_partial_configuration(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), [])
            ->assertSessionHasErrors(['bank_name', 'branch_name', 'account_type', 'account_number', 'account_holder', 'account_holder_kana']);
        $this->assertNull(OrderSetting::current()->bank_transfer);
    }

    #[TestWith(['account_type', '定期'])]
    #[TestWith(['account_number', '123-4567'])]
    #[TestWith(['account_number', '123abc'])]
    #[TestWith(['bank_code', 'A001'])]
    #[TestWith(['branch_code', '01A'])]
    #[TestWith(['bank_name', ['配列']])]
    #[TestWith(['account_holder_kana', ''])]
    public function test_invalid_fields_are_rejected(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details([$field => $value]))
            ->assertSessionHasErrors($field);
        $this->assertNull(OrderSetting::current()->bank_transfer);
    }

    #[TestWith(['bank_name', 101])]
    #[TestWith(['branch_name', 101])]
    #[TestWith(['account_number', 21])]
    #[TestWith(['instructions', 2001])]
    public function test_field_lengths_are_limited(string $field, int $length): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details([$field => str_repeat('1', $length)]))
            ->assertSessionHasErrors($field);
    }

    public function test_digits_are_normalized_without_losing_leading_zeroes_or_fixing_account_length_to_seven(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details([
            'bank_name' => ' テスト銀行 ', 'bank_code' => '０００１', 'branch_code' => ' ００１ ',
            'account_number' => '００１２３４５６７８９０', 'account_type' => '貯蓄', 'instructions' => '',
        ]))->assertSessionHasNoErrors();
        $details = OrderSetting::current()->bank_transfer;
        $this->assertSame('テスト銀行', $details['bank_name']);
        $this->assertSame('0001', $details['bank_code']);
        $this->assertSame('001', $details['branch_code']);
        $this->assertSame('001234567890', $details['account_number']);
        $this->assertSame('貯蓄', $details['account_type']);
        $this->assertNull($details['instructions']);
    }

    public function test_guest_customer_and_unverified_admin_cannot_access_or_update_settings(): void
    {
        foreach (['admin.settings.index', 'admin.settings.bank-transfer.edit'] as $route) {
            $this->get(route($route))->assertRedirect('/admin/login');
        }
        $this->put(route('admin.settings.bank-transfer.update'), BankTransfer::details())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings.index'))->assertForbidden();
        $this->get(route('admin.settings.bank-transfer.edit'))->assertForbidden();
        $this->put(route('admin.settings.bank-transfer.update'), BankTransfer::details())->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get(route('admin.settings.index'))->assertRedirect('/email/verify');
        $this->put(route('admin.settings.bank-transfer.update'), BankTransfer::details())->assertRedirect('/email/verify');
        $this->assertNull(OrderSetting::current()->bank_transfer);
    }

    public function test_account_is_encrypted_not_logged_or_flashed_on_validation_errors(): void
    {
        Log::spy();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.settings.bank-transfer.update'), BankTransfer::details())->assertSessionHasNoErrors();
        $raw = DB::table('order_settings')->where('id', 1)->value('bank_transfer');
        $this->assertStringNotContainsString('001234567890', $raw);
        $this->assertStringNotContainsString('テスト銀行', $raw);
        $this->put(route('admin.settings.bank-transfer.update'), BankTransfer::details(['bank_name' => '']))->assertSessionHasErrors('bank_name');
        $this->assertNull(session()->getOldInput('account_number'));
        foreach (['debug', 'info', 'warning', 'error'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }
}
