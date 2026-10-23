<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function supplierInput(array $overrides = []): array
    {
        return array_replace([
            'code' => 'SUP-001',
            'name' => '北海道青果株式会社',
            'contact_name' => '仕入 太郎',
            'email' => 'purchase@example.com',
            'phone' => '011-000-0000',
            'postal_code' => '060-0000',
            'address' => '北海道札幌市中央区',
            'notes' => '午前中に連絡',
            'status' => 'active',
            'purchase_settings' => [],
        ], $overrides);
    }

    private function purchaseSetting(Product $product, array $overrides = []): array
    {
        return array_replace([
            'product_id' => $product->id,
            'supplier_product_code' => 'POTATO-10',
            'default_purchase_price' => '1800.5000',
            'minimum_order_quantity' => '5',
            'lead_time_days' => '3',
            'is_primary' => true,
            'status' => 'active',
        ], $overrides);
    }

    public function test_supplier_routes_require_verified_admin(): void
    {
        $supplier = Supplier::factory()->create();

        $this->get('/admin/suppliers')->assertRedirect('/admin/login');
        $this->post('/admin/suppliers', $this->supplierInput())->assertRedirect('/admin/login');
        $this->put('/admin/suppliers/'.$supplier->id, $this->supplierInput())->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/suppliers')->assertForbidden();
        $this->get('/admin/suppliers/create')->assertForbidden();
        $this->get('/admin/suppliers/'.$supplier->id.'/edit')->assertForbidden();
        $this->post('/admin/suppliers', $this->supplierInput())->assertForbidden();
        $this->put('/admin/suppliers/'.$supplier->id, $this->supplierInput())->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/suppliers')->assertRedirect('/email/verify');
        $this->post('/admin/suppliers', $this->supplierInput())->assertRedirect('/email/verify');
        $this->put('/admin/suppliers/'.$supplier->id, $this->supplierInput())->assertRedirect('/email/verify');
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_admin_creates_supplier_with_purchase_setting(): void
    {
        $product = Product::factory()->create(['name' => '北海道産 男爵 10kg', 'sku' => 'POTATO-10']);
        $input = $this->supplierInput([
            'code' => ' sup-abc ',
            'email' => ' PURCHASE@EXAMPLE.COM ',
            'purchase_settings' => [$this->purchaseSetting($product)],
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())->post('/admin/suppliers', $input);

        $supplier = Supplier::firstOrFail();
        $response->assertRedirect('/admin/suppliers/'.$supplier->id.'/edit')->assertSessionHasNoErrors();
        $this->assertSame('SUP-ABC', $supplier->code);
        $this->assertSame('purchase@example.com', $supplier->email);
        $this->assertDatabaseHas('product_suppliers', [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_product_code' => 'POTATO-10',
            'default_purchase_price' => '1800.5000',
            'minimum_order_quantity' => '5.0000',
            'lead_time_days' => 3,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $this->get('/admin/suppliers/'.$supplier->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Suppliers/Form')
            ->where('supplier.name', '北海道青果株式会社')
            ->where('supplier.purchase_settings.0.product.name', '北海道産 男爵 10kg')
            ->has('products', 1));
    }

    public function test_blank_code_is_automatically_generated(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/suppliers', $this->supplierInput(['code' => '']))
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith('S-', Supplier::firstOrFail()->code);
    }

    #[TestWith(['name', '', '仕入先名を入力してください。'])]
    #[TestWith(['code', 'コード!', '仕入先コードは半角英大文字・数字・ハイフン・アンダースコアで入力してください。'])]
    #[TestWith(['email', 'invalid', 'メールアドレスの形式を確認してください。'])]
    #[TestWith(['purchase_settings.0.default_purchase_price', '-1', '基準仕入単価は0以上で入力してください。'])]
    #[TestWith(['purchase_settings.0.minimum_order_quantity', '0', '最小発注数は0より大きい数値で入力してください。'])]
    #[TestWith(['purchase_settings.0.lead_time_days', '3651', '標準納期は0〜3650日の整数で入力してください。'])]
    public function test_invalid_supplier_values_are_rejected(string $path, mixed $value, string $message): void
    {
        $product = Product::factory()->create();
        $input = $this->supplierInput(['purchase_settings' => [$this->purchaseSetting($product)]]);
        data_set($input, $path, $value);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/suppliers', $input)
            ->assertSessionHasErrors([$path => $message]);

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('product_suppliers', 0);
    }

    public function test_duplicate_products_and_inactive_primary_setting_are_rejected(): void
    {
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/suppliers', $this->supplierInput(['purchase_settings' => [
            $this->purchaseSetting($product),
            $this->purchaseSetting($product, ['supplier_product_code' => 'OTHER']),
        ]]))->assertSessionHasErrors('purchase_settings.1.product_id');

        $this->post('/admin/suppliers', $this->supplierInput(['purchase_settings' => [
            $this->purchaseSetting($product, ['status' => 'inactive', 'is_primary' => true]),
        ]]))->assertSessionHasErrors(['purchase_settings.0.is_primary' => '無効な仕入設定は通常の仕入先にできません。']);

        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_duplicate_supplier_code_is_rejected(): void
    {
        Supplier::factory()->create(['code' => 'SUP-001']);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/suppliers', $this->supplierInput())
            ->assertSessionHasErrors(['code' => 'この仕入先コードはすでに使われています。']);

        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_update_uses_lock_and_omitted_settings_become_inactive(): void
    {
        $supplier = Supplier::factory()->create(['name' => '更新前', 'lock_version' => 1]);
        $product = Product::factory()->create();
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id, 'is_primary' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/suppliers/'.$supplier->id, $this->supplierInput([
            'name' => '古い画面の変更',
            'lock_version' => 0,
            'purchase_settings' => [$this->purchaseSetting($product)],
        ]))->assertSessionHasErrors('lock_version');
        $this->assertSame('更新前', $supplier->fresh()->name);

        $this->put('/admin/suppliers/'.$supplier->id, $this->supplierInput([
            'name' => '更新後',
            'lock_version' => 1,
            'purchase_settings' => [],
        ]))->assertSessionHasNoErrors();

        $updatedSupplier = $supplier->fresh();
        $this->assertSame('更新後', $updatedSupplier->name);
        $this->assertSame(2, $updatedSupplier->lock_version);
        $this->assertDatabaseHas('product_suppliers', ['id' => $setting->id, 'status' => 'inactive', 'is_primary' => false]);
    }

    public function test_disabling_supplier_clears_primary_without_removing_purchase_setting(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $setting = ProductSupplier::factory()->primary()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/suppliers/'.$supplier->id, $this->supplierInput([
            'code' => $supplier->code,
            'name' => $supplier->name,
            'status' => 'inactive',
            'lock_version' => 0,
            'purchase_settings' => [$this->purchaseSetting($product)],
        ]))->assertSessionHasNoErrors();

        $updatedSetting = $setting->fresh();
        $this->assertSame('active', $updatedSetting->status);
        $this->assertFalse($updatedSetting->is_primary);
    }

    public function test_selecting_primary_supplier_clears_previous_primary(): void
    {
        $product = Product::factory()->create();
        $first = Supplier::factory()->create();
        $second = Supplier::factory()->create();
        $firstSetting = ProductSupplier::factory()->primary()->create(['supplier_id' => $first->id, 'product_id' => $product->id]);
        $secondSetting = ProductSupplier::factory()->create(['supplier_id' => $second->id, 'product_id' => $product->id]);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/suppliers/'.$second->id, $this->supplierInput([
            'code' => $second->code,
            'name' => $second->name,
            'lock_version' => 0,
            'purchase_settings' => [$this->purchaseSetting($product, ['supplier_product_code' => 'SECOND'])],
        ]))->assertSessionHasNoErrors();

        $this->assertFalse($firstSetting->fresh()->is_primary);
        $this->assertTrue($secondSetting->fresh()->is_primary);
    }

    public function test_supplier_list_searches_and_filters_with_counts(): void
    {
        $active = Supplier::factory()->create(['name' => '北海道青果', 'code' => 'HOKKAIDO', 'contact_name' => '田中']);
        Supplier::factory()->inactive()->create(['name' => '九州青果', 'code' => 'KYUSHU']);
        ProductSupplier::factory()->create(['supplier_id' => $active->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/suppliers?q=田中&status=active')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Suppliers/Index')
                ->where('suppliers.total', 1)
                ->where('suppliers.data.0.id', $active->id)
                ->where('suppliers.data.0.active_purchase_settings_count', 1)
                ->where('counts.all', 2)
                ->where('counts.active', 1));
    }
}
