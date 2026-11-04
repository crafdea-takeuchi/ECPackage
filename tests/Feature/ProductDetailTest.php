<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductGroup;
use App\Models\ProductImage;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_can_view_public_product_detail_with_images_attributes_and_variants(): void
    {
        $group = ProductGroup::factory()->create(['name' => '歯ブラシ', 'description' => '毎日使う歯ブラシです。']);
        $definition = ProductAttributeDefinition::factory()->for($group, 'group')->create(['name' => 'サイズ', 'sort_order' => 1]);
        $unit = Unit::factory()->create(['name' => '本', 'quantity_scale' => 0]);
        $taxRate = TaxRate::factory()->create(['name' => '標準税率', 'rate' => '10.00']);
        $product = Product::factory()->create([
            'name' => '歯ブラシ S',
            'description' => "小さめヘッドです。\n奥歯まで磨けます。",
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'tax_rate_id' => $taxRate->id,
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'default_sale_price' => '1000.0000',
        ]);
        $category = Category::factory()->create(['name' => 'オーラルケア']);
        $product->categories()->attach($category);
        $product->attributeValues()->create([
            'product_attribute_definition_id' => $definition->id,
            'value' => 'S',
        ]);
        $lastImage = ProductImage::factory()->for($product)->create(['sort_order' => 1]);
        $firstImage = ProductImage::factory()->for($product)->create(['sort_order' => 0]);

        $variant = Product::factory()->create([
            'name' => '歯ブラシ M',
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'tax_rate_id' => $taxRate->id,
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'default_sale_price' => '1200.0000',
        ]);
        $variant->attributeValues()->create([
            'product_attribute_definition_id' => $definition->id,
            'value' => 'M',
        ]);
        Product::factory()->create([
            'product_group_id' => $group->id,
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
            'publication_status' => 'private',
            'default_sale_price' => '900.0000',
        ]);

        $this->get(route('shop.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shop/Show')
                ->where('product.id', $product->id)
                ->where('product.description', "小さめヘッドです。\n奥歯まで磨けます。")
                ->where('product.price_including_tax', '1100.0000')
                ->where('product.categories.0.name', 'オーラルケア')
                ->where('product.images.0.id', $firstImage->id)
                ->where('product.images.1.id', $lastImage->id)
                ->where('product.attribute_values.0.definition.name', 'サイズ')
                ->where('product.attribute_values.0.value', 'S')
                ->where('variants', fn ($variants) => count($variants) === 2)
                ->where('variants.0.id', $product->id)
                ->where('variants.0.attribute_values.0.value', 'S')
                ->where('variants.1.id', $variant->id)
                ->where('variants.1.attribute_values.0.value', 'M'));
    }

    public function test_product_detail_hides_products_that_are_not_sellable(): void
    {
        $private = Product::factory()->create([
            'registration_status' => 'confirmed',
            'publication_status' => 'private',
            'default_sale_price' => '100.0000',
        ]);
        $provisional = Product::factory()->create([
            'registration_status' => 'provisional',
            'publication_status' => 'public',
            'default_sale_price' => '100.0000',
        ]);
        $unpriced = Product::factory()->create([
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'default_sale_price' => null,
        ]);

        foreach ([$private, $provisional, $unpriced] as $product) {
            $this->get(route('shop.show', $product))->assertNotFound();
        }
    }

    public function test_admin_can_save_product_description(): void
    {
        $unit = Unit::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/products', [
            'images_present' => true,
            'images' => [],
            'kind' => 'single',
            'name' => '説明付き商品',
            'description' => '商品の詳しい説明です。',
            'sku' => 'DETAIL-001',
            'product_group_id' => null,
            'unit_id' => $unit->id,
            'default_sale_price' => null,
            'tax_rate_id' => null,
            'registration_status' => 'provisional',
            'publication_status' => 'private',
            'shortage_policy' => 'reject',
            'category_ids' => [],
            'attribute_values' => [],
            'bundle_components' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'sku' => 'DETAIL-001',
            'description' => '商品の詳しい説明です。',
        ]);
    }
}
