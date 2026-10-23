<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductAttributeValue;
use App\Models\ProductGroup;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductGroupManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function groupInput(array $overrides = []): array
    {
        return array_replace([
            'name' => '果汁グミ',
            'description' => '味と容量が異なる商品をまとめます。',
            'status' => 'active',
            'attributes' => [
                ['name' => '味', 'code' => 'flavor', 'input_type' => 'select', 'options' => ['ブドウ', 'ミカン'], 'is_required' => true, 'sort_order' => 10, 'status' => 'active'],
                ['name' => '容量', 'code' => 'weight', 'input_type' => 'number', 'options' => [], 'is_required' => true, 'sort_order' => 20, 'status' => 'active'],
            ],
        ], $overrides);
    }

    private function productInput(ProductGroup $group, array $values = [], array $overrides = []): array
    {
        return array_replace([
            'name' => '果汁グミ ブドウ味',
            'sku' => 'GUMMY-GRAPE',
            'product_group_id' => $group->id,
            'attribute_values' => $values,
            'category_ids' => [],
            'unit_id' => Unit::factory()->create()->id,
            'registration_status' => 'provisional',
            'publication_status' => 'private',
            'default_sale_price' => null,
        ], $overrides);
    }

    public function test_group_routes_require_verified_admin(): void
    {
        $group = ProductGroup::factory()->create();
        $this->get('/admin/product-groups')->assertRedirect('/admin/login');
        $this->post('/admin/product-groups', $this->groupInput())->assertRedirect('/admin/login');
        $this->put('/admin/product-groups/'.$group->id, $this->groupInput())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/product-groups')->assertForbidden();
        $this->get('/admin/product-groups/create')->assertForbidden();
        $this->get('/admin/product-groups/'.$group->id.'/edit')->assertForbidden();
        $this->post('/admin/product-groups', $this->groupInput())->assertForbidden();
        $this->put('/admin/product-groups/'.$group->id, $this->groupInput())->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/product-groups')->assertRedirect('/email/verify');
        $this->post('/admin/product-groups', $this->groupInput())->assertRedirect('/email/verify');
        $this->put('/admin/product-groups/'.$group->id, $this->groupInput())->assertRedirect('/email/verify');
        $this->assertDatabaseCount('product_groups', 1);
    }

    public function test_admin_creates_group_with_normalized_attribute_definitions(): void
    {
        $admin = User::factory()->admin()->create();
        $input = $this->groupInput();
        $input['attributes'][0]['code'] = ' Flavor ';
        $input['attributes'][0]['options'] = [' ブドウ ', '', 'ミカン'];
        $input['attributes'][0]['id'] = 999;
        unset($input['attributes'][0]['id']);
        $this->actingAs($admin)->post('/admin/product-groups', $input)->assertSessionHasNoErrors();
        $group = ProductGroup::firstOrFail();
        $this->assertSame('果汁グミ', $group->name);
        $this->assertSame(0, $group->lock_version);
        $this->assertDatabaseHas('product_attribute_definitions', ['product_group_id' => $group->id, 'code' => 'flavor', 'name' => '味', 'input_type' => 'select', 'is_required' => true]);
        $definition = ProductAttributeDefinition::where('code', 'flavor')->firstOrFail();
        $this->assertSame(['ブドウ', 'ミカン'], $definition->options);
        $this->get('/admin/product-groups')->assertInertia(fn (Assert $page) => $page->component('Admin/ProductGroups/Index')->where('groups.0.definitions_count', 2)->where('groups.0.products_count', 0));
        $this->get('/admin/product-groups/'.$group->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/ProductGroups/Form')->has('productGroup.definitions', 2));
    }

    #[TestWith(['name', '', '基本商品名を入力してください。'])]
    #[TestWith(['status', 'deleted', '有効または無効を選択してください。'])]
    #[TestWith(['attributes.0.name', '', '属性名を入力してください。'])]
    #[TestWith(['attributes.0.code', '1flavor', '属性コードは小文字の英字から始め、英小文字・数字・アンダースコアで入力してください。'])]
    #[TestWith(['attributes.0.input_type', 'date', '入力形式を選び直してください。'])]
    public function test_invalid_group_values_are_rejected(string $path, mixed $value, string $message): void
    {
        $input = $this->groupInput();
        data_set($input, $path, $value);
        $this->actingAs(User::factory()->admin()->create())->post('/admin/product-groups', $input)->assertSessionHasErrors([$path => $message]);
        $this->assertDatabaseCount('product_groups', 0);
    }

    public function test_duplicate_codes_and_select_without_options_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $input = $this->groupInput();
        $input['attributes'][1]['code'] = 'FLAVOR';
        $this->post('/admin/product-groups', $input)->assertSessionHasErrors(['attributes.1.code' => '同じ属性コードが重複しています。']);
        $input = $this->groupInput();
        $input['attributes'][0]['options'] = [];
        $this->post('/admin/product-groups', $input)->assertSessionHasErrors(['attributes.0.options' => '選択式の属性には選択肢を1つ以上設定してください。']);
        $this->assertDatabaseCount('product_groups', 0);
    }

    public function test_group_update_uses_lock_and_rejects_attribute_from_another_group(): void
    {
        $group = ProductGroup::factory()->create(['lock_version' => 1, 'name' => '更新済み']);
        $otherDefinition = ProductAttributeDefinition::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->put('/admin/product-groups/'.$group->id, [...$this->groupInput(), 'lock_version' => 0])->assertSessionHasErrors('lock_version');
        $this->assertSame('更新済み', $group->fresh()->name);
        $input = $this->groupInput(['lock_version' => 1]);
        $input['attributes'][0]['id'] = $otherDefinition->id;
        $this->put('/admin/product-groups/'.$group->id, $input)->assertSessionHasErrors('attributes');
        $this->assertDatabaseCount('product_attribute_definitions', 1);
    }

    public function test_new_definition_cannot_reuse_inactive_definition_code(): void
    {
        $group = ProductGroup::factory()->create();
        ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'code' => 'flavor', 'status' => 'inactive']);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/product-groups/'.$group->id, [...$this->groupInput(['attributes' => [[
            'name' => '新しい味', 'code' => 'flavor', 'input_type' => 'text', 'options' => [], 'is_required' => false, 'sort_order' => 0, 'status' => 'active',
        ]]]), 'lock_version' => 0])->assertSessionHasErrors(['attributes' => '同じ属性コードがこの基本商品ですでに使われています。']);
        $this->assertDatabaseCount('product_attribute_definitions', 1);
    }

    public function test_removed_definition_is_disabled_and_existing_values_are_preserved(): void
    {
        $group = ProductGroup::factory()->create();
        $definition = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'code' => 'flavor']);
        $product = Product::factory()->create(['product_group_id' => $group->id]);
        ProductAttributeValue::factory()->create(['product_id' => $product->id, 'product_attribute_definition_id' => $definition->id, 'value' => 'ブドウ']);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/product-groups/'.$group->id, [...$this->groupInput(['attributes' => []]), 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_attribute_definitions', ['id' => $definition->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('product_attribute_values', ['product_id' => $product->id, 'product_attribute_definition_id' => $definition->id, 'value' => 'ブドウ']);
    }

    public function test_used_attribute_type_and_used_select_option_cannot_be_changed(): void
    {
        $group = ProductGroup::factory()->create();
        $definition = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'code' => 'flavor', 'input_type' => 'select', 'options' => ['ブドウ', 'ミカン']]);
        $value = ProductAttributeValue::factory()->create(['product_attribute_definition_id' => $definition->id, 'value' => 'ブドウ']);
        $attribute = ['id' => $definition->id, 'name' => '味', 'code' => 'flavor', 'input_type' => 'text', 'options' => [], 'is_required' => false, 'sort_order' => 0, 'status' => 'active'];
        $this->actingAs(User::factory()->admin()->create())->put('/admin/product-groups/'.$group->id, [...$this->groupInput(['attributes' => [$attribute]]), 'lock_version' => 0])->assertSessionHasErrors(['attributes' => '商品で使用中の属性は入力形式を変更できません。']);
        $attribute['input_type'] = 'select';
        $attribute['options'] = ['ミカン'];
        $this->put('/admin/product-groups/'.$group->id, [...$this->groupInput(['attributes' => [$attribute]]), 'lock_version' => 0])->assertSessionHasErrors(['attributes' => '商品で使用中の選択肢は削除できません。']);
        $this->assertSame('ブドウ', $value->fresh()->value);
    }

    public function test_provisional_product_can_omit_required_attributes_but_confirmed_product_cannot(): void
    {
        $group = ProductGroup::factory()->create();
        $required = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '味', 'code' => 'flavor', 'is_required' => true]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/products', $this->productInput($group))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->post('/admin/products', $this->productInput($group, [], ['sku' => 'GUMMY-ORANGE', 'registration_status' => 'confirmed', 'default_sale_price' => '200']))->assertSessionHasErrors(["attribute_values.$required->id" => '味を入力してください。']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_product_saves_text_number_and_select_attributes(): void
    {
        $group = ProductGroup::factory()->create();
        $text = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '産地', 'code' => 'origin', 'input_type' => 'text']);
        $number = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '容量', 'code' => 'weight', 'input_type' => 'number']);
        $select = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '味', 'code' => 'flavor', 'input_type' => 'select', 'options' => ['ブドウ', 'ミカン']]);
        $values = [$text->id => '北海道', $number->id => '54.5', $select->id => 'ブドウ'];
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->productInput($group, $values))->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame($group->id, $product->product_group_id);
        $this->assertSame($values, $product->attributeValues()->orderBy('product_attribute_definition_id')->pluck('value', 'product_attribute_definition_id')->all());
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.group.name', $group->name)->has('product.attribute_values', 3));
    }

    #[TestWith(['number', '12.1234567'])]
    #[TestWith(['number', 'not-a-number'])]
    #[TestWith(['select', '存在しない味'])]
    public function test_invalid_dynamic_attribute_value_is_rejected(string $type, string $value): void
    {
        $group = ProductGroup::factory()->create();
        $definition = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '属性', 'input_type' => $type, 'options' => $type === 'select' ? ['選択肢'] : null]);
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->productInput($group, [$definition->id => $value]))->assertSessionHasErrors("attribute_values.$definition->id");
        $this->assertDatabaseCount('products', 0);
    }

    public function test_attribute_from_another_group_and_inactive_new_group_are_rejected(): void
    {
        $group = ProductGroup::factory()->create();
        $other = ProductAttributeDefinition::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->productInput($group, [$other->id => 'value']))->assertSessionHasErrors('attribute_values');
        $inactive = ProductGroup::factory()->create(['status' => 'inactive']);
        $this->post('/admin/products', $this->productInput($inactive))->assertSessionHasErrors(['product_group_id' => '有効な基本商品を選択してください。']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_changing_group_removes_old_values_and_saves_new_values(): void
    {
        $oldGroup = ProductGroup::factory()->create();
        $oldDefinition = ProductAttributeDefinition::factory()->create(['product_group_id' => $oldGroup->id]);
        $newGroup = ProductGroup::factory()->create();
        $newDefinition = ProductAttributeDefinition::factory()->create(['product_group_id' => $newGroup->id]);
        $product = Product::factory()->create(['product_group_id' => $oldGroup->id]);
        ProductAttributeValue::factory()->create(['product_id' => $product->id, 'product_attribute_definition_id' => $oldDefinition->id, 'value' => 'old']);
        $input = $this->productInput($newGroup, [$newDefinition->id => 'new'], ['sku' => $product->sku, 'unit_id' => $product->unit_id, 'lock_version' => 0]);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $input)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('product_attribute_values', ['product_id' => $product->id, 'product_attribute_definition_id' => $oldDefinition->id]);
        $this->assertDatabaseHas('product_attribute_values', ['product_id' => $product->id, 'product_attribute_definition_id' => $newDefinition->id, 'value' => 'new']);
    }

    public function test_group_and_attribute_filters_find_matching_products(): void
    {
        $group = ProductGroup::factory()->create(['name' => '果汁グミ']);
        $definition = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'name' => '味']);
        $grape = Product::factory()->create(['product_group_id' => $group->id, 'name' => 'ブドウ味']);
        $orange = Product::factory()->create(['product_group_id' => $group->id, 'name' => 'ミカン味']);
        ProductAttributeValue::factory()->create(['product_id' => $grape->id, 'product_attribute_definition_id' => $definition->id, 'value' => 'ブドウ']);
        ProductAttributeValue::factory()->create(['product_id' => $orange->id, 'product_attribute_definition_id' => $definition->id, 'value' => 'ミカン']);
        Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/products?product_group_id='.$group->id)->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
        $this->get('/admin/products?attribute_definition_id='.$definition->id.'&attribute_value=ブドウ')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $grape->id));
        $this->get('/admin/products?attribute_definition_id='.$definition->id)->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
    }
}
