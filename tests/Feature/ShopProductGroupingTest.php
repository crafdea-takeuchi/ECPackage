<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductGroup;
use App\Models\ProductImage;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShopProductGroupingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_replace([
            'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => '1000',
        ], $overrides));
    }

    public function test_public_products_are_grouped_and_ungrouped_products_remain_individual(): void
    {
        $a = ProductGroup::factory()->create(['name' => '鉄フライパン']);
        $b = ProductGroup::factory()->create(['name' => '鍋']);
        $first = $this->product(['name' => 'A 20cm', 'product_group_id' => $a->id]);
        $this->product(['name' => 'A 24cm', 'product_group_id' => $a->id]);
        $this->product(['name' => 'B 鍋', 'product_group_id' => $b->id]);
        $this->product(['name' => 'C 単独']);
        $this->product(['name' => 'D 単独']);
        $this->product(['name' => 'A 非公開', 'product_group_id' => $a->id, 'publication_status' => 'private', 'default_sale_price' => '1']);
        $this->product(['product_group_id' => ProductGroup::factory()->create()->id, 'publication_status' => 'private']);

        $this->get('/shop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 4)->has('products.data', 4)
            ->where('products.data.0.name', '鉄フライパン')->where('products.data.0.id', $first->id)
            ->where('products.data.0.variant_count', 2)->has('products.data.0.variants', 2)->where('products.data.0.images', [])->where('products.data.1.name', '鍋')
            ->where('products.data.2.name', 'C 単独')->where('products.data.3.name', 'D 単独'));
    }

    public function test_price_range_attributes_and_images_only_use_visible_matching_variants(): void
    {
        $group = ProductGroup::factory()->create(['name' => 'フライパン']);
        $size = ProductAttributeDefinition::factory()->for($group, 'group')->create(['name' => 'サイズ', 'sort_order' => 0]);
        $rate = TaxRate::factory()->create(['rate' => '10.00']);
        $first = $this->product(['name' => 'A', 'product_group_id' => $group->id, 'tax_rate_id' => $rate->id]);
        $second = $this->product(['name' => 'B', 'product_group_id' => $group->id, 'default_sale_price' => '2000', 'tax_rate_id' => $rate->id]);
        $third = $this->product(['name' => 'C', 'product_group_id' => $group->id, 'tax_rate_id' => $rate->id]);
        $private = $this->product(['name' => '非公開', 'publication_status' => 'private', 'product_group_id' => $group->id, 'default_sale_price' => '1']);
        foreach ([[$first, '20cm'], [$second, '24cm'], [$third, '20cm'], [$private, '秘密']] as [$product, $value]) {
            $product->attributeValues()->create(['product_attribute_definition_id' => $size->id, 'value' => $value]);
        }
        $image = ProductImage::factory()->for($second)->create(['sort_order' => 0]);
        ProductImage::factory()->for($second)->create(['sort_order' => 1]);
        ProductImage::factory()->for($private)->create(['sort_order' => 0]);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price_including_tax', '1100.0000')->where('products.data.0.max_price_including_tax', '2200.0000')
            ->where('products.data.0.has_price_range', true)->where('products.data.0.variant_count', 3)
            ->has('products.data.0.variants', 3)
            ->where('products.data.0.variants.0.price_including_tax', '1100.0000')
            ->where('products.data.0.variants.1.price_including_tax', '2200.0000')
            ->where('products.data.0.variants.0.attribute_values.0.value', '20cm')
            ->where('products.data.0.variants.1.attribute_values.0.value', '24cm')
            ->where('products.data.0.images.0.id', $image->id)
            ->where('products.data.0.attribute_summary', [['name' => 'サイズ', 'values' => ['20cm', '24cm'], 'has_more' => false]]));
        $this->get('/shop?attribute=24cm')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $second->id)
            ->where('products.data.0.variant_count', 1)->where('products.data.0.price_including_tax', '2200.0000')
            ->where('products.data.0.has_price_range', false));
        $this->get('/shop?attribute=秘密')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_same_prices_have_no_range_and_first_named_variant_image_is_used(): void
    {
        $group = ProductGroup::factory()->create();
        $b = $this->product(['name' => 'B', 'product_group_id' => $group->id]);
        $a = $this->product(['name' => 'A', 'product_group_id' => $group->id]);
        ProductImage::factory()->for($b)->create();
        $image = ProductImage::factory()->for($a)->create();

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $a->id)->where('products.data.0.images.0.id', $image->id)
            ->where('products.data.0.has_price_range', false)->where('products.data.0.variant_count', 2));
    }

    #[TestWith(['商品検索'])]
    #[TestWith(['GROUP-SKU'])]
    #[TestWith(['基本商品検索'])]
    public function test_search_matches_product_name_code_or_group_name(string $term): void
    {
        $group = ProductGroup::factory()->create(['name' => '基本商品検索']);
        $this->product(['name' => '商品検索', 'sku' => 'GROUP-SKU', 'product_group_id' => $group->id]);
        $this->product(['name' => '無関係', 'sku' => 'OTHER-SKU']);

        $this->get('/shop?q='.urlencode($term))->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.name', '基本商品検索'));
    }

    public function test_category_and_stock_filters_summarize_only_matching_public_products(): void
    {
        $group = ProductGroup::factory()->create();
        $category = Category::factory()->create();
        $a = $this->product(['name' => 'A', 'product_group_id' => $group->id]);
        $b = $this->product(['name' => 'B', 'product_group_id' => $group->id, 'shortage_policy' => 'backorder', 'default_sale_price' => '2000']);
        $a->categories()->attach($category);
        InventoryLot::factory()->create(['product_id' => $a->id, 'current_quantity' => '10']);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.data.0.stock_status', 'available')->where('products.data.0.can_backorder', true));
        $this->get('/shop?category_id='.$category->id)->assertInertia(fn (Assert $page) => $page->where('products.data.0.variant_count', 1)->where('products.data.0.id', $a->id));
        $this->get('/shop?availability=backorder')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $b->id)->where('products.data.0.stock_status', 'backorder')->where('products.data.0.variant_count', 1));
        $b->update(['shortage_policy' => 'reject']);
        $this->get('/shop?availability=sold_out')->assertInertia(fn (Assert $page) => $page->where('products.data.0.stock_status', 'sold_out'));
    }

    public function test_bundle_stock_is_aggregated_in_sql_and_matches_component_requirements(): void
    {
        $component = $this->product();
        InventoryLot::factory()->create(['product_id' => $component->id, 'current_quantity' => '5']);
        $bundle = $this->product(['kind' => 'bundle', 'name' => 'セット']);
        $bundle->bundleComponents()->create(['component_product_id' => $component->id, 'quantity' => '2', 'sort_order' => 0]);

        $this->get('/shop?q='.urlencode('セット').'&availability=available')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.available_quantity', 2)->where('products.data.0.stock_status', 'available'));
    }

    public function test_pagination_counts_groups_and_preserves_search_filters(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $group = ProductGroup::factory()->create(['name' => '検索グループ '.$i]);
            $this->product(['name' => sprintf('商品 %02d A', $i), 'product_group_id' => $group->id]);
            $this->product(['name' => sprintf('商品 %02d B', $i), 'product_group_id' => $group->id]);
        }

        $this->get('/shop?q='.urlencode('検索グループ'))->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 13)->where('products.per_page', 12)->where('products.last_page', 2)->has('products.data', 12)
            ->where('products.next_page_url', fn ($url): bool => str_contains($url, 'page=2') && str_contains($url, 'q=')));
        $this->get('/shop?q='.urlencode('検索グループ').'&page=2')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('products.current_page', 2)->where('products.data.0.variant_count', 2));
    }

    public function test_group_card_representative_opens_existing_detail_with_all_public_variants(): void
    {
        $group = ProductGroup::factory()->create();
        $first = $this->product(['name' => 'A', 'product_group_id' => $group->id]);
        for ($i = 0; $i < 21; $i++) {
            $this->product(['name' => sprintf('B%02d', $i), 'product_group_id' => $group->id]);
        }
        $this->product(['product_group_id' => $group->id, 'publication_status' => 'private']);

        $this->get('/shop/'.$first->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shop/Show')->where('product.id', $first->id)->has('variants', 22)
            ->missing('product.inventory_lots')->missing('variants.0.inventory_lots'));
    }

    public function test_admin_list_still_lists_each_product_individually(): void
    {
        $group = ProductGroup::factory()->create();
        $this->product(['product_group_id' => $group->id]);
        $this->product(['product_group_id' => $group->id]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Products/Index')->where('products.total', 2)->has('products.data', 2));
    }

    public function test_listed_variant_can_be_added_to_cart_as_the_selected_product(): void
    {
        $group = ProductGroup::factory()->create();
        $first = $this->product(['name' => 'A', 'product_group_id' => $group->id]);
        $selected = $this->product(['name' => 'B', 'product_group_id' => $group->id, 'default_sale_price' => '1500']);
        InventoryLot::factory()->create(['product_id' => $selected->id, 'current_quantity' => '5']);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.variants.1.id', $selected->id)
            ->where('products.data.0.variants.1.available_quantity', 5));

        $this->post('/cart', ['product_id' => $selected->id, 'quantity' => '2'])->assertSessionHasNoErrors();

        $this->assertSame('2.0000', session('cart')[$selected->id]);
        $this->assertArrayNotHasKey($first->id, session('cart'));
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('items.0.product.id', $selected->id)->where('items.0.amount', '3000.0000'));
    }

    public function test_query_count_does_not_grow_with_variant_count(): void
    {
        $group = ProductGroup::factory()->create();
        $this->product(['product_group_id' => $group->id]);
        DB::enableQueryLog();
        $this->get('/shop')->assertOk();
        $firstCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 8; $i++) {
            $this->product(['product_group_id' => $group->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/shop')->assertOk();
        $secondCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($firstCount, $secondCount);
        $this->assertLessThanOrEqual(16, $secondCount);
    }
}
