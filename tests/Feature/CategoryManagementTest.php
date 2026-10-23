<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => '野菜', 'parent_id' => null, 'sort_order' => 0, 'status' => 'active'], $overrides);
    }

    private function productInput(array $overrides = []): array
    {
        return array_replace(['name' => '男爵', 'unit_id' => Unit::factory()->create()->id, 'registration_status' => 'provisional', 'publication_status' => 'private', 'category_ids' => []], $overrides);
    }

    public function test_category_routes_require_verified_admin(): void
    {
        $category = Category::factory()->create();
        $this->get('/admin/categories')->assertRedirect('/admin/login');
        $this->post('/admin/categories', $this->input())->assertRedirect('/admin/login');
        $this->put('/admin/categories/'.$category->id, $this->input())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/categories')->assertForbidden();
        $this->get('/admin/categories/create')->assertForbidden();
        $this->get('/admin/categories/'.$category->id.'/edit')->assertForbidden();
        $this->post('/admin/categories', $this->input())->assertForbidden();
        $this->put('/admin/categories/'.$category->id, $this->input())->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/categories')->assertRedirect('/email/verify');
        $this->post('/admin/categories', $this->input())->assertRedirect('/email/verify');
        $this->put('/admin/categories/'.$category->id, $this->input())->assertRedirect('/email/verify');
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_admin_creates_category_and_child_and_sees_sorted_tree(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/categories/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Categories/Form')->where('category', null));
        $this->post('/admin/categories', $this->input(['lock_version' => 100, 'id' => 999]))->assertRedirect('/admin/categories');
        $root = Category::firstOrFail();
        $this->assertSame(0, $root->lock_version);
        $this->assertNotSame(999, $root->id);
        $this->post('/admin/categories', $this->input(['name' => '根菜', 'parent_id' => $root->id, 'sort_order' => 20]))->assertSessionHasNoErrors();
        $this->post('/admin/categories', $this->input(['name' => '葉物', 'parent_id' => $root->id, 'sort_order' => 10]))->assertSessionHasNoErrors();
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page->component('Admin/Categories/Index')->has('categories', 3)->where('categories.0.path', '野菜')->where('categories.1.path', '野菜 → 葉物')->where('categories.2.path', '野菜 → 根菜'));
    }

    public function test_move_and_rename_preserve_product_links_and_subtree(): void
    {
        $root = Category::factory()->create(['name' => '野菜']);
        $potato = Category::factory()->create(['name' => 'ジャガイモ', 'parent_id' => $root->id]);
        $child = Category::factory()->create(['name' => '男爵', 'parent_id' => $potato->id]);
        $newParent = Category::factory()->create(['name' => '根菜', 'parent_id' => $root->id]);
        $product = Product::factory()->create();
        $product->categories()->attach($child);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/categories/'.$potato->id, $this->input(['name' => 'じゃがいも', 'parent_id' => $newParent->id, 'lock_version' => 0]))->assertRedirect('/admin/categories')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('category_product', ['category_id' => $child->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('categories', ['id' => $potato->id, 'parent_id' => $newParent->id, 'name' => 'じゃがいも', 'lock_version' => 1]);
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page->where('categories.3.path', '野菜 → 根菜 → じゃがいも → 男爵'));
        $this->assertSame($product->name, $product->fresh()->name);
        $this->put('/admin/categories/'.$potato->id, $this->input(['name' => 'じゃがいも', 'parent_id' => null, 'lock_version' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($potato->fresh()->parent_id);
        $this->assertSame($potato->id, $child->fresh()->parent_id);
    }

    public function test_self_and_descendant_parents_are_rejected_and_not_offered(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $leaf = Category::factory()->create(['parent_id' => $child->id]);
        $other = Category::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        foreach ([$root->id, $child->id, $leaf->id] as $parentId) {
            $this->put('/admin/categories/'.$root->id, $this->input(['parent_id' => $parentId, 'lock_version' => 0]))->assertSessionHasErrors(['parent_id' => '自分自身や配下の分類を親にすることはできません。']);
        }
        $this->assertNull($root->fresh()->parent_id);
        $this->get('/admin/categories/'.$root->id.'/edit')->assertInertia(fn (Assert $page) => $page->has('parents', 1)->where('parents.0.id', $other->id));
    }

    public function test_cycle_is_rechecked_after_another_category_has_moved(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->put('/admin/categories/'.$second->id, $this->input(['parent_id' => $first->id, 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->put('/admin/categories/'.$first->id, $this->input(['parent_id' => $second->id, 'lock_version' => 0]))->assertSessionHasErrors('parent_id');
        $this->assertNull($first->fresh()->parent_id);
    }

    public function test_stale_category_edit_cannot_overwrite_changes(): void
    {
        $category = Category::factory()->create(['name' => '更新済み', 'lock_version' => 1]);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/categories/'.$category->id, $this->input(['lock_version' => 0]))->assertSessionHasErrors('lock_version');
        $this->assertSame('更新済み', $category->fresh()->name);
    }

    #[TestWith(['name', '', '商品分類名を入力してください。'])]
    #[TestWith(['sort_order', -1, '表示順は0〜999999の整数で入力してください。'])]
    #[TestWith(['sort_order', '1.5', '表示順は0〜999999の整数で入力してください。'])]
    #[TestWith(['sort_order', 1000000, '表示順は0〜999999の整数で入力してください。'])]
    #[TestWith(['status', 'deleted', '有効または無効を選択してください。'])]
    #[TestWith(['parent_id', 99999, '親分類を選び直してください。'])]
    public function test_invalid_categories_are_not_created(string $field, mixed $value, string $message): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/categories', $this->input([$field => $value]))->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_long_name_is_rejected_and_missing_category_returns_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/categories', $this->input(['name' => str_repeat('あ', 101)]))->assertSessionHasErrors(['name' => '商品分類名は100文字以内で入力してください。']);
        $this->get('/admin/categories/9999/edit')->assertNotFound();
        $this->put('/admin/categories/9999', $this->input(['lock_version' => 0]))->assertNotFound();
    }

    public function test_product_accepts_multiple_categories_and_can_be_uncategorized(): void
    {
        $categories = Category::factory()->count(2)->create();
        $ids = $categories->modelKeys();
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->productInput(['category_ids' => $ids]))->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame($ids, $product->categories()->orderBy('categories.id')->pluck('categories.id')->all());
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->has('product.categories', 2));
        $this->put('/admin/products/'.$product->id, $this->productInput(['unit_id' => $product->unit_id, 'category_ids' => [], 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('category_product', 0);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_invalid_category_ids_and_duplicates_do_not_create_products(): void
    {
        $category = Category::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/products', $this->productInput(['category_ids' => [99999]]))->assertSessionHasErrors('category_ids.0');
        $this->post('/admin/products', $this->productInput(['category_ids' => [$category->id, $category->id]]))->assertSessionHasErrors('category_ids.0');
        $this->post('/admin/products', $this->productInput(['category_ids' => 'invalid']))->assertSessionHasErrors('category_ids');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_inactive_ancestor_blocks_new_assignments_and_rolls_back_product(): void
    {
        $root = Category::factory()->create(['status' => 'inactive']);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->productInput(['category_ids' => [$child->id]]))->assertSessionHasErrors('category_ids');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('category_product', 0);
    }

    public function test_disabling_retains_links_and_existing_assignments_can_be_kept_or_removed(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $product = Product::factory()->create();
        $product->categories()->attach($child);
        $this->actingAs(User::factory()->admin()->create());
        $this->put('/admin/categories/'.$root->id, $this->input(['status' => 'inactive', 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('category_product', ['category_id' => $child->id, 'product_id' => $product->id]);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('categories.1.effective_active', false));
        $this->put('/admin/products/'.$product->id, $this->productInput(['category_ids' => [$child->id], 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('category_product', 1);
        $this->put('/admin/products/'.$product->id, $this->productInput(['category_ids' => [], 'lock_version' => 1]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('category_product', 0);
        $this->put('/admin/products/'.$product->id, $this->productInput(['name' => '保存してはいけない', 'category_ids' => [$child->id], 'lock_version' => 2]))->assertSessionHasErrors('category_ids');
        $this->assertSame('男爵', $product->fresh()->name);
        $this->assertSame(2, $product->fresh()->lock_version);
    }

    public function test_reenabling_parent_does_not_reenable_individually_disabled_child(): void
    {
        $root = Category::factory()->create(['status' => 'inactive']);
        Category::factory()->create(['parent_id' => $root->id, 'status' => 'active']);
        Category::factory()->create(['parent_id' => $root->id, 'status' => 'inactive']);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/categories/'.$root->id, $this->input(['lock_version' => 0]))->assertSessionHasNoErrors();
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page->where('categories.1.effective_active', true)->where('categories.2.effective_active', false));
    }

    public function test_filter_includes_descendants_without_duplicate_products_and_follows_moves(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $other = Category::factory()->create();
        $product = Product::factory()->create();
        $product->categories()->attach([$root->id, $child->id]);
        $unclassified = Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/products?category_id='.$root->id)->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $product->id));
        $this->get('/admin/products?uncategorized=1')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $unclassified->id));
        $product->categories()->detach($root);
        $this->put('/admin/categories/'.$child->id, $this->input(['parent_id' => $other->id, 'lock_version' => 0]))->assertSessionHasNoErrors();
        $this->get('/admin/products?category_id='.$root->id)->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/admin/products?category_id='.$other->id)->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $this->get('/admin/products?category_id=99999')->assertSessionHasErrors('category_id');
    }

    public function test_omitted_categories_preserve_existing_association_on_update(): void
    {
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $product->categories()->attach($category);
        $data = $this->productInput(['lock_version' => 0]);
        unset($data['category_ids']);
        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('category_product', ['category_id' => $category->id, 'product_id' => $product->id]);
    }
}
