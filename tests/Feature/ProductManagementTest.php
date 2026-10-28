<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\TaxRateSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => '新しい商品', 'sku' => '', 'unit_id' => Unit::factory()->create()->id, 'registration_status' => 'provisional', 'publication_status' => 'private', 'default_sale_price' => null], $overrides);
    }

    public function test_guests_customers_and_unverified_admins_cannot_manage_products(): void
    {
        $product = Product::factory()->create();
        $this->get('/admin/products')->assertRedirect('/admin/login');
        $this->post('/admin/products', [])->assertRedirect('/admin/login');
        $this->put('/admin/products/'.$product->id, [])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/products')->assertForbidden();
        $this->get('/admin/products/create')->assertForbidden();
        $this->get('/admin/products/'.$product->id.'/edit')->assertForbidden();
        $this->post('/admin/products', [])->assertForbidden();
        $this->put('/admin/products/'.$product->id, [])->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/products')->assertRedirect('/email/verify');
        $this->post('/admin/products', [])->assertRedirect('/email/verify');
        $this->put('/admin/products/'.$product->id, [])->assertRedirect('/email/verify');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_sees_empty_list_and_registration_form(): void
    {
        $this->seed(UnitSeeder::class);
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Products/Index')->where('products.total', 0));
        $this->get('/admin/products/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Products/Form')->has('units', 4)->where('product', null));
    }

    public function test_provisional_registration_generates_code_and_ignores_protected_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/products', $this->input(['created_by' => 999, 'confirmed_by' => 999, 'merged_into_id' => 999]))->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertStringStartsWith('P-', $product->sku);
        $this->assertSame($admin->id, $product->created_by);
        $this->assertSame('single', $product->kind);
        $this->assertNull($product->confirmed_by);
        $this->assertNull($product->merged_into_id);
        $this->assertNull($product->default_sale_price);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'registration_status' => 'provisional', 'publication_status' => 'private']);
    }

    public function test_admin_can_register_confirmed_product_directly_without_publishing(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/products', $this->input(['sku' => ' abc-001 ', 'registration_status' => 'confirmed', 'default_sale_price' => '1200.1250']))->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame('ABC-001', $product->sku);
        $this->assertSame('1200.1250', $product->default_sale_price);
        $this->assertSame($admin->id, $product->confirmed_by);
        $this->assertTrue($product->confirmed_at->equalTo(now()));
        $this->assertSame('private', $product->publication_status);
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Products/Form')->where('product.registration_status', 'confirmed'));
    }

    public function test_admin_promotes_provisional_product_and_can_toggle_visibility(): void
    {
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();
        $data = $this->input(['unit_id' => $product->unit_id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => '0', 'lock_version' => 0]);
        $this->actingAs($admin)->put('/admin/products/'.$product->id, $data)->assertRedirect('/admin/products/'.$product->id.'/edit')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => $product->sku, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'confirmed_by' => $admin->id, 'created_by' => $product->created_by, 'lock_version' => 1]);
        $confirmedAt = $product->fresh()->confirmed_at;
        $this->put('/admin/products/'.$product->id, [...$data, 'publication_status' => 'private', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('private', $product->fresh()->publication_status);
        $this->assertTrue($product->fresh()->confirmed_at->equalTo($confirmedAt));
    }

    #[TestWith(['name', '', '販売商品名を入力してください。'])]
    #[TestWith(['sku', '日本語', '商品コードは半角英数字・ハイフン・アンダースコアで入力してください。'])]
    #[TestWith(['publication_status', 'public', '仮登録の販売商品は公開できません。公開状態を確認してください。'])]
    #[TestWith(['registration_status', 'merged', '登録状態を選び直してください。'])]
    #[TestWith(['default_sale_price', '-1', '単価は0以上、整数15桁・小数4桁以内で入力してください。'])]
    #[TestWith(['default_sale_price', '1.00001', '単価は0以上、整数15桁・小数4桁以内で入力してください。'])]
    #[TestWith(['default_sale_price', '1000000000000000', '単価は0以上、整数15桁・小数4桁以内で入力してください。'])]
    #[TestWith(['unit_id', 999999, '有効な単位を選択してください。'])]
    public function test_invalid_product_is_not_saved(string $field, mixed $value, string $message): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input([$field => $value]))->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_form_provides_active_tax_rates_including_current_inactive_rate(): void
    {
        $this->seed(TaxRateSeeder::class);
        $inactive = TaxRate::factory()->create(['code' => 'old', 'name' => '旧税率', 'rate' => '5.00', 'status' => 'inactive']);
        $product = Product::factory()->create(['tax_rate_id' => $inactive->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/products/create')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Products/Form')
            ->has('taxRates', 2)
            ->where('taxRates.0.code', 'reduced')
            ->where('taxRates.0.rate', '8.00')
            ->where('taxRates.1.code', 'standard'));
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->has('taxRates', 3)
            ->where('taxRates.0.code', 'old')
            ->where('product.tax_rate_id', $inactive->id));
    }

    public function test_admin_can_set_change_and_clear_product_tax_rate(): void
    {
        $this->seed(TaxRateSeeder::class);
        $reduced = TaxRate::where('code', 'reduced')->firstOrFail();
        $standard = TaxRate::where('code', 'standard')->firstOrFail();
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/products', $this->input(['tax_rate_id' => $reduced->id]))->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame($reduced->id, $product->tax_rate_id);
        $this->assertSame('8.00', $product->taxRate->rate);

        $this->put('/admin/products/'.$product->id, $this->input(['unit_id' => $product->unit_id, 'tax_rate_id' => $standard->id, 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertSame($standard->id, $product->fresh()->tax_rate_id);

        $this->put('/admin/products/'.$product->id, $this->input(['unit_id' => $product->unit_id, 'tax_rate_id' => '', 'lock_version' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->tax_rate_id);
    }

    public function test_inactive_tax_rate_cannot_be_newly_set_but_current_one_can_be_kept(): void
    {
        $inactive = TaxRate::factory()->create(['status' => 'inactive']);
        $product = Product::factory()->create(['tax_rate_id' => $inactive->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/products', $this->input(['tax_rate_id' => $inactive->id]))->assertSessionHasErrors(['tax_rate_id' => '有効な税率区分を選択してください。']);
        $this->post('/admin/products', $this->input(['tax_rate_id' => 999999]))->assertSessionHasErrors(['tax_rate_id' => '有効な税率区分を選択してください。']);
        $this->assertDatabaseCount('products', 1);

        $this->put('/admin/products/'.$product->id, $this->input(['unit_id' => $product->unit_id, 'tax_rate_id' => $inactive->id, 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertSame($inactive->id, $product->fresh()->tax_rate_id);
    }

    public function test_confirmation_requires_price_and_active_unit(): void
    {
        $unit = Unit::factory()->create(['status' => 'inactive']);
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(['registration_status' => 'confirmed', 'unit_id' => $unit->id]))->assertSessionHasErrors(['default_sale_price' => '本登録には標準販売単価が必要です。', 'unit_id' => '有効な単位を選択してください。']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_duplicate_code_is_rejected_but_own_code_can_be_kept(): void
    {
        $product = Product::factory()->create(['sku' => 'EXISTING']);
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/products', $this->input(['sku' => 'existing']))->assertSessionHasErrors(['sku' => 'この商品コードはすでに使われています。']);
        $this->put('/admin/products/'.$product->id, $this->input(['sku' => 'EXISTING', 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_stale_edit_is_rejected_without_overwriting(): void
    {
        $product = Product::factory()->create(['lock_version' => 2, 'name' => '最新の商品名']);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $this->input(['lock_version' => 1]))->assertSessionHasErrors('lock_version');
        $this->assertSame('最新の商品名', $product->fresh()->name);
    }

    public function test_confirmed_product_cannot_revert_or_change_unit(): void
    {
        $product = Product::factory()->create(['registration_status' => 'confirmed', 'default_sale_price' => '10']);
        $this->actingAs(User::factory()->admin()->create());
        $this->put('/admin/products/'.$product->id, $this->input(['lock_version' => 0]))->assertSessionHasErrors('registration_status');
        $this->put('/admin/products/'.$product->id, $this->input(['registration_status' => 'confirmed', 'default_sale_price' => '10', 'lock_version' => 0]))->assertSessionHasErrors('unit_id');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'unit_id' => $product->unit_id, 'registration_status' => 'confirmed', 'lock_version' => 0]);
    }

    public function test_search_combines_name_or_code_with_status_and_preserves_pagination(): void
    {
        $unit = Unit::factory()->create();
        $admin = User::factory()->admin()->create();
        Product::factory()->count(21)->create(['name' => '検索対象', 'unit_id' => $unit->id, 'created_by' => $admin->id]);
        Product::factory()->create(['name' => '対象外', 'unit_id' => $unit->id, 'created_by' => $admin->id, 'sku' => 'FIND-001', 'registration_status' => 'confirmed', 'publication_status' => 'public']);
        $this->actingAs($admin)->get('/admin/products?q=検索&registration_status=provisional&publication_status=private')->assertInertia(fn (Assert $page) => $page->where('products.total', 21)->has('products.data', 20)->where('products.last_page', 2)->where('products.next_page_url', fn ($url) => str_contains($url, 'registration_status=provisional')));
        $this->get('/admin/products?q=FIND-001&registration_status=confirmed&publication_status=public')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.sku', 'FIND-001'));
        $this->get('/admin/products?q=absent')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_missing_and_merged_products_are_not_editable(): void
    {
        $product = Product::factory()->create(['registration_status' => 'merged']);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products/999999/edit')->assertNotFound();
        $this->get('/admin/products/'.$product->id.'/edit')->assertNotFound();
        $this->put('/admin/products/'.$product->id, $this->input(['lock_version' => 0]))->assertSessionHasErrors('registration_status');
        $this->assertSame('merged', $product->fresh()->registration_status);
    }

    public function test_unit_seeder_can_be_run_twice_without_overwriting_changes(): void
    {
        $this->seed(UnitSeeder::class);
        Unit::where('code', 'piece')->update(['name' => '個数']);
        $this->seed(UnitSeeder::class);
        $this->assertDatabaseCount('units', 4);
        $this->assertDatabaseHas('units', ['code' => 'piece', 'name' => '個数']);
    }

    public function test_zero_is_a_search_term_and_does_not_return_all_products(): void
    {
        $admin = User::factory()->admin()->create();
        $unit = Unit::factory()->create();
        Product::factory()->create(['sku' => 'ZERO-0', 'name' => 'ゼロ', 'unit_id' => $unit->id, 'created_by' => $admin->id]);
        Product::factory()->create(['sku' => 'OTHER', 'name' => 'その他', 'unit_id' => $unit->id, 'created_by' => $admin->id]);
        $this->actingAs($admin)->get('/admin/products?q=0')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.sku', 'ZERO-0'));
    }
}
