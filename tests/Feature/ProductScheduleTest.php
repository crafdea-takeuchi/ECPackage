<?php

namespace Tests\Feature;

use App\Actions\Products\ProductSchedule;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\BankTransfer;
use Tests\TestCase;

class ProductScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 00:00:00', 'UTC'));
    }

    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_replace([
            'registration_status' => 'confirmed', 'publication_status' => 'public',
            'default_sale_price' => 100, 'shortage_policy' => 'backorder',
        ], $overrides));
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => '期間テスト', 'unit_id' => Unit::factory()->create()->id,
            'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 100], $overrides);
    }

    private function checkoutInput(): array
    {
        return ['shipping_name' => '購入者', 'shipping_postal_code' => '100-0001', 'shipping_prefecture' => '東京都',
            'shipping_address_line1' => '千代田区1-1', 'payment_method' => 'bank_transfer'];
    }

    #[TestWith([[], 'on_sale', true])]
    #[TestWith([['publication_status' => 'private', 'sale_ends_at' => '2026-11-17 23:00:00'], 'private', false])]
    #[TestWith([['publication_starts_at' => '2026-11-18 00:00:01'], 'before_publication', false])]
    #[TestWith([['publication_starts_at' => '2026-11-18 00:00:00'], 'on_sale', true])]
    #[TestWith([['publication_ends_at' => '2026-11-18 00:00:00'], 'publication_ended', false])]
    #[TestWith([['publication_ends_at' => '2026-11-18 00:00:01'], 'on_sale', true])]
    #[TestWith([['sale_starts_at' => '2026-11-18 00:00:01'], 'before_sale', true])]
    #[TestWith([['sale_starts_at' => '2026-11-18 00:00:00'], 'on_sale', true])]
    #[TestWith([['sale_ends_at' => '2026-11-18 00:00:00'], 'sale_ended', true])]
    public function test_state_and_sql_visibility_agree_at_boundaries(array $fields, string $state, bool $visible): void
    {
        $product = $this->product($fields);
        $this->assertSame($state, $product->sale_state);
        $this->assertSame(ProductSchedule::LABELS[$state], $product->sale_status);
        $this->assertSame($visible, ProductSchedule::visible($product));
        $this->assertSame($visible, Product::whereKey($product->id)->publiclyVisible()->exists());
        if ($visible) {
            $this->get('/shop/'.$product->id)->assertOk();
        } else {
            $this->get('/shop/'.$product->id)->assertNotFound();
        }
    }

    public function test_tokyo_inputs_are_saved_in_utc_and_redisplayed_without_drift(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $data = $this->input([
            'publication_starts_at' => '2026-11-18T08:00:00', 'publication_ends_at' => '2026-11-19T00:00:00',
            'sale_starts_at' => '2026-11-18T09:00:00', 'sale_ends_at' => '2026-11-18T23:59:59',
        ]);
        $this->post(route('admin.products.store'), $data)->assertSessionHasNoErrors();
        $product = Product::where('name', '期間テスト')->firstOrFail();
        $this->assertSame('2026-11-17 23:00:00', $product->publication_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-18 15:00:00', $product->publication_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('on_sale', $product->sale_state);
        $this->get(route('admin.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('scheduleValues.publication_starts_at', '2026-11-18T08:00:00')
            ->where('scheduleValues.sale_starts_at', '2026-11-18T09:00:00'));
        $this->put(route('admin.products.update', $product), [...$data, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-17 23:00:00', $product->fresh()->publication_starts_at->format('Y-m-d H:i:s'));
        $this->put(route('admin.products.update', $product), [...$data, 'lock_version' => 1, 'sale_ends_at' => ''])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->sale_ends_at);
    }

    #[TestWith([['publication_starts_at' => '2026-02-30T10:00'], 'publication_starts_at'])]
    #[TestWith([['sale_starts_at' => 'tomorrow'], 'sale_starts_at'])]
    #[TestWith([['publication_starts_at' => '2026-11-18T10:00', 'publication_ends_at' => '2026-11-18T10:00'], 'publication_ends_at'])]
    #[TestWith([['sale_starts_at' => '2026-11-18T10:00', 'sale_ends_at' => '2026-11-18T09:00'], 'sale_ends_at'])]
    #[TestWith([['publication_starts_at' => '2026-11-18T10:00', 'sale_starts_at' => '2026-11-18T09:00'], 'sale_starts_at'])]
    #[TestWith([['publication_ends_at' => '2026-11-18T10:00', 'sale_ends_at' => '2026-11-18T11:00'], 'sale_ends_at'])]
    #[TestWith([['publication_ends_at' => '2026-11-18T10:00', 'sale_starts_at' => '2026-11-18T10:00'], 'sale_starts_at'])]
    public function test_invalid_datetime_ranges_cannot_be_registered(array $fields, string $error): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.products.store'), $this->input($fields))
            ->assertSessionHasErrors($error);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_groups_and_variants_include_only_currently_public_members_with_independent_sales_windows(): void
    {
        $group = ProductGroup::factory()->create();
        $before = $this->product(['product_group_id' => $group->id, 'sale_starts_at' => now()->addHour()]);
        $ended = $this->product(['product_group_id' => $group->id, 'sale_ends_at' => now()]);
        $this->product(['product_group_id' => $group->id, 'publication_starts_at' => now()->addHour()]);
        $this->product(['product_group_id' => ProductGroup::factory(), 'publication_ends_at' => now()]);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)
            ->where('products.data.0.variant_count', 2)->has('products.data.0.variants', 2));
        $this->get('/shop/'.$before->id)->assertInertia(fn (Assert $page) => $page
            ->has('variants', 2)->where('product.sale_state', 'before_sale')
            ->where('product.purchase_unavailable_reason', fn ($reason) => str_contains($reason, '販売開始前')));
        $this->get('/shop/'.$ended->id)->assertInertia(fn (Assert $page) => $page->where('product.sale_state', 'sale_ended'));
        $before->update(['publication_status' => 'private']);
        $ended->update(['publication_ends_at' => now()]);
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    #[TestWith([['sale_starts_at' => '2026-11-18 00:00:01']])]
    #[TestWith([['sale_ends_at' => '2026-11-18 00:00:00']])]
    #[TestWith([['publication_ends_at' => '2026-11-18 00:00:00']])]
    #[TestWith([['publication_status' => 'private']])]
    public function test_cart_add_and_quantity_changes_reject_unpurchasable_products(array $fields): void
    {
        $product = $this->product($fields);
        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('product_id');
        $this->withSession(['cart' => [$product->id => '1']])->patch('/cart/'.$product->id, ['quantity' => 2])->assertSessionHasErrors('product_id');
        $this->assertSame('1', session('cart')[$product->id]);
    }

    public function test_expiry_after_cart_addition_blocks_checkout_and_order_without_changing_stock(): void
    {
        $product = $this->product(['shortage_policy' => 'reject', 'sale_ends_at' => now()->addMinute()]);
        $lot = InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 5]);
        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $this->actingAs(User::factory()->create())->get('/checkout')->assertRedirectToRoute('cart.index')->assertSessionHasErrors('cart');
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('items.0.purchase_error', '販売は終了しました。')->has('cartErrors', 1));
        $this->post('/checkout', $this->checkoutInput())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_lines', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('5.0000', $lot->fresh()->current_quantity);
        $this->assertSame('1.0000', session('cart')[$product->id]);
    }

    public function test_order_is_rolled_back_if_sale_ends_during_confirmation(): void
    {
        BankTransfer::configure();
        $product = $this->product(['shortage_policy' => 'reject']);
        $lot = InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 5]);
        Order::created(function () use ($product): void {
            Product::whereKey($product->id)->update(['sale_ends_at' => now()->subSecond()]);
        });
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$product->id => '1']])
            ->post('/checkout', $this->checkoutInput())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_lines', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('order_backorders', 0);
        $this->assertSame('5.0000', $lot->fresh()->current_quantity);
    }
}
