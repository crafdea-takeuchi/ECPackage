<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductAttributeValue;
use App\Models\ProductGroup;
use App\Models\ProductImage;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceiptLine;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BasicProductWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function definition(ProductGroup $group): ProductAttributeDefinition
    {
        return ProductAttributeDefinition::factory()->create([
            'product_group_id' => $group->id, 'name' => 'サイズ', 'code' => 'size',
            'input_type' => 'select', 'options' => ['20cm', '24cm', '26cm'], 'is_required' => true,
        ]);
    }

    private function input(ProductGroup $group, ProductAttributeDefinition $definition, array $overrides = []): array
    {
        return array_replace([
            'name' => '鉄フライパン20cm', 'sku' => '', 'product_group_id' => $group->id,
            'from_product_group_id' => $group->id, 'unit_id' => Unit::factory()->create()->id,
            'registration_status' => 'confirmed', 'publication_status' => 'public',
            'default_sale_price' => '3000', 'attribute_values' => [$definition->id => '20cm'],
        ], $overrides);
    }

    public function test_group_summary_uses_all_members_and_includes_empty_groups(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create(['name' => 'A鉄フライパン']);
        $empty = ProductGroup::factory()->create(['name' => 'Z空']);
        $this->definition($group);
        $first = Product::factory()->create(['product_group_id' => $group->id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 3000]);
        Product::factory()->create(['product_group_id' => $group->id, 'registration_status' => 'confirmed', 'publication_status' => 'private', 'default_sale_price' => 4500]);
        Product::factory()->create(['product_group_id' => $group->id]);
        Product::factory()->create(['default_sale_price' => 1]);
        InventoryLot::factory()->create(['product_id' => $first->id, 'current_quantity' => 4]);

        $this->get(route('admin.product-groups.index'))->assertInertia(fn (Assert $page) => $page
            ->has('groups', 2)->where('groups.0.products_count', 3)
            ->where('groups.0.public_products_count', 1)->where('groups.0.private_products_count', 2)
            ->where('groups.0.stocked_products_count', 1)
            ->where('groups.0.products_min_default_sale_price', fn ($value) => (float) $value === 3000.0)
            ->where('groups.0.products_max_default_sale_price', fn ($value) => (float) $value === 4500.0)
            ->where('groups.0.definitions.0.name', 'サイズ')
            ->where('groups.0.show_url', route('admin.product-groups.show', $group, false))
            ->where('groups.1.id', $empty->id)->where('groups.1.products_count', 0)
            ->where('groups.1.products_min_default_sale_price', null));
    }

    public function test_detail_limits_members_and_eager_loads_display_data(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = $this->definition($group);
        $tax = TaxRate::factory()->create();
        $product = Product::factory()->create(['product_group_id' => $group->id, 'tax_rate_id' => $tax->id, 'default_sale_price' => 3000, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'shortage_policy' => 'backorder']);
        ProductAttributeValue::factory()->create(['product_id' => $product->id, 'product_attribute_definition_id' => $definition->id, 'value' => '20cm']);
        ProductImage::factory()->create(['product_id' => $product->id]);
        InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 3]);
        Product::factory()->create(['product_group_id' => ProductGroup::factory()]);
        Product::factory()->create();

        $this->get(route('admin.product-groups.show', $group))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ProductGroups/Show')->where('productGroup.id', $group->id)
            ->where('products.total', 1)->where('products.data.0.id', $product->id)
            ->where('products.data.0.attribute_values.0.definition.name', 'サイズ')
            ->where('products.data.0.attribute_values.0.value', '20cm')
            ->where('products.data.0.default_sale_price', '3000.0000')
            ->where('products.data.0.available_quantity', fn ($value) => (float) $value === 3.0)
            ->where('products.data.0.tax_rate.id', $tax->id)->has('products.data.0.unit')
            ->where('products.data.0.shortage_policy', 'backorder')
            ->where('products.data.0.publication_status', 'public')->has('products.data.0.images', 1)
            ->where('products.data.0.edit_url', route('admin.products.edit', ['product' => $product, 'from_product_group_id' => $group->id], false))
            ->where('createProductUrl', route('admin.products.create', ['product_group_id' => $group->id, 'from_product_group_id' => $group->id], false)));
    }

    public function test_detail_paginates_and_handles_empty_and_inactive_groups(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $url = route('admin.product-groups.show', $group);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('products.total', 0)->has('products.data', 0));
        Product::factory()->count(21)->create(['product_group_id' => $group->id]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('products.data', 20)->where('products.total', 21)->where('products.last_page', 2));
        $this->get($url.'?page=2')->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.current_page', 2));
        $group->update(['status' => 'inactive']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('createProductUrl', null)->where('products.total', 21));
    }

    public function test_parent_is_preselected_and_store_and_edit_return_to_parent(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = $this->definition($group);
        $params = ['product_group_id' => $group->id, 'from_product_group_id' => $group->id];
        $this->get(route('admin.products.create', $params))->assertInertia(fn (Assert $page) => $page
            ->where('selectedGroupId', (string) $group->id)->where('returnGroup.id', $group->id)
            ->where('returnGroup.url', route('admin.product-groups.show', $group, false))
            ->where('groups.0.definitions.0.id', $definition->id));
        $data = $this->input($group, $definition);
        $this->post(route('admin.products.store'), $data)->assertSessionHasNoErrors()->assertRedirectToRoute('admin.product-groups.show', $group);
        $product = Product::where('product_group_id', $group->id)->firstOrFail();
        $this->get(route('admin.products.edit', ['product' => $product, 'from_product_group_id' => $group->id]))
            ->assertInertia(fn (Assert $page) => $page->where('returnGroup.id', $group->id)->where('saveUrl', route('admin.products.update', $product, false)));
        $this->put(route('admin.products.update', $product), [...$data, 'unit_id' => $product->unit_id, 'name' => '改名', 'lock_version' => 0])
            ->assertSessionHasNoErrors()->assertRedirectToRoute('admin.product-groups.show', $group);
        $this->assertSame('改名', $product->fresh()->name);
    }

    public function test_required_attribute_and_duplicate_combinations_are_rejected_atomically(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = $this->definition($group);
        $data = $this->input($group, $definition);
        foreach ([null, '', '   '] as $blank) {
            $this->post(route('admin.products.store'), [...$data, 'attribute_values' => [$definition->id => $blank]])
                ->assertSessionHasErrors('attribute_values.'.$definition->id);
        }
        $this->post(route('admin.products.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.products.store'), [...$data, 'name' => '重複'])
            ->assertSessionHasErrors(['attribute_values' => 'この属性の組み合わせはすでに登録されています。']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_attribute_values', 1);
        $other = $this->input($group, $definition, ['attribute_values' => [$definition->id => '24cm']]);
        $this->post(route('admin.products.store'), $other)->assertSessionHasNoErrors();
        $product = Product::latest('id')->first();
        $this->put(route('admin.products.update', $product), [...$data, 'unit_id' => $product->unit_id, 'lock_version' => 0])
            ->assertSessionHasErrors('attribute_values');
        $this->assertSame('24cm', $product->attributeValues()->first()->value);
        $this->assertSame(0, $product->fresh()->lock_version);
    }

    public function test_numeric_equivalent_combinations_are_duplicates_but_other_groups_are_independent(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = ProductAttributeDefinition::factory()->create(['product_group_id' => $group->id, 'input_type' => 'number']);
        $data = $this->input($group, $definition, ['attribute_values' => [$definition->id => '20']]);
        $this->post(route('admin.products.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.products.store'), [...$data, 'attribute_values' => [$definition->id => '20.000000']])->assertSessionHasErrors('attribute_values');
        $other = ProductGroup::factory()->create();
        $this->post(route('admin.products.store'), $this->input($other, $this->definition($other)))->assertSessionHasNoErrors();
    }

    public function test_inactive_parent_prevents_additions_without_modifying_existing_products(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = $this->definition($group);
        $data = $this->input($group, $definition);
        $this->post(route('admin.products.store'), $data)->assertSessionHasNoErrors();
        $product = Product::where('product_group_id', $group->id)->firstOrFail();
        $attributes = [$definition->only(['id', 'name', 'code', 'input_type', 'options', 'is_required', 'sort_order', 'status'])];
        $this->put(route('admin.product-groups.update', $group), ['name' => $group->name, 'status' => 'inactive', 'lock_version' => 0, 'attributes' => $attributes])->assertSessionHasNoErrors();
        $this->assertSame('public', $product->fresh()->publication_status);
        $this->assertSame('confirmed', $product->fresh()->registration_status);
        $this->post(route('admin.products.store'), [...$data, 'attribute_values' => [$definition->id => '24cm']])->assertSessionHasErrors('product_group_id');
        $this->get(route('admin.products.create', ['product_group_id' => $group->id]))->assertSessionHasErrors('product_group_id');
        $this->put(route('admin.products.update', $product), [...$data, 'unit_id' => $product->unit_id, 'lock_version' => 0, 'name' => '既存編集'])
            ->assertSessionHasNoErrors();
        $this->delete(route('admin.product-groups.show', $group))->assertStatus(405);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => '既存編集']);
    }

    public function test_product_search_supports_group_links_ungrouped_and_stock_filters(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()->create(['product_group_id' => $group->id, 'sku' => 'IRON-PAN']);
        $standalone = Product::factory()->create(['shortage_policy' => 'backorder']);
        InventoryLot::factory()->create(['product_id' => $product->id, 'purchase_receipt_line_id' => PurchaseReceiptLine::factory()->create(['product_id' => $product->id, 'product_supplier_id' => ProductSupplier::factory()->create(['product_id' => $product->id])->id])->id, 'current_quantity' => 5]);
        $this->get(route('admin.products.index', ['product_group_id' => $group->id, 'q' => 'IRON']))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.group.show_url', route('admin.product-groups.show', $group, false)));
        $this->get(route('admin.products.index', ['ungrouped' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $standalone->id));
        $this->get(route('admin.products.index', ['availability' => 'available']))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $product->id));
        $this->get(route('admin.products.index', ['availability' => 'backorder']))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $standalone->id));
    }

    public function test_group_detail_has_same_admin_authorization_as_existing_management(): void
    {
        $group = ProductGroup::factory()->create();
        $url = route('admin.product-groups.show', $group);
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())->get($url)->assertRedirect('/email/verify');
    }

    public function test_index_and_detail_query_counts_do_not_grow_with_members(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $definition = $this->definition($group);
        $add = function () use ($group, $definition): void {
            $product = Product::factory()->create(['product_group_id' => $group->id]);
            ProductAttributeValue::factory()->create(['product_id' => $product->id, 'product_attribute_definition_id' => $definition->id]);
            ProductImage::factory()->create(['product_id' => $product->id]);
        };
        $add();
        $count = function (string $url): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $index = $count(route('admin.product-groups.index'));
        $detail = $count(route('admin.product-groups.show', $group));
        foreach (range(1, 19) as $unused) {
            $add();
            ProductGroup::factory()->create();
        }
        $this->assertSame($index, $count(route('admin.product-groups.index')));
        $this->assertSame($detail, $count(route('admin.product-groups.show', $group)));
    }

    public function test_combination_compares_all_active_attributes_and_excludes_current_product(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $size = $this->definition($group);
        $color = ProductAttributeDefinition::factory()->create([
            'product_group_id' => $group->id, 'name' => 'カラー', 'input_type' => 'select',
            'options' => ['黒', '赤'], 'is_required' => true,
        ]);
        $black = $this->input($group, $size, ['attribute_values' => [$size->id => '20cm', $color->id => '黒']]);
        $this->post(route('admin.products.store'), $black)->assertSessionHasNoErrors();
        $red = [...$black, 'attribute_values' => [$color->id => '赤', $size->id => '20cm']];
        $this->post(route('admin.products.store'), $red)->assertSessionHasNoErrors();
        $product = Product::latest('id')->first();
        $this->put(route('admin.products.update', $product), [...$red, 'unit_id' => $product->unit_id, 'lock_version' => 0])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.products.store'), [...$black, 'attribute_values' => [$color->id => '黒', $size->id => '20cm']])
            ->assertSessionHasErrors('attribute_values');
        $this->assertDatabaseCount('products', 2);
    }

    public function test_bundle_quantities_follow_component_stock_without_loading_all_lots(): void
    {
        $this->admin();
        $group = ProductGroup::factory()->create();
        $component = Product::factory()->create(['registration_status' => 'confirmed']);
        $bundle = Product::factory()->create(['product_group_id' => $group->id, 'kind' => 'bundle', 'registration_status' => 'confirmed']);
        $bundle->bundleComponents()->create(['component_product_id' => $component->id, 'quantity' => 2, 'sort_order' => 1]);
        InventoryLot::factory()->create(['product_id' => $component->id, 'current_quantity' => 7]);
        $this->get(route('admin.product-groups.show', $group))->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $bundle->id)
            ->where('products.data.0.available_quantity', fn ($value) => (float) $value === 3.0));
        $this->get(route('admin.product-groups.index'))->assertInertia(fn (Assert $page) => $page->where('groups.0.stocked_products_count', 1));
    }
}
