<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CustomerShoppingTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{product: Product, lot: InventoryLot, admin: User} */
    private function sellableProduct(string $sku = 'SHOP-001', string $quantity = '10', int $quantityScale = 0, array $productOverrides = []): array
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $unit = Unit::factory()->create(['name' => $quantityScale === 0 ? '個' : 'kg', 'quantity_scale' => $quantityScale]);
        $product = Product::factory()->create(array_replace([
            'sku' => $sku,
            'name' => '公開テスト商品 '.$sku,
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'default_sale_price' => '300.5000',
        ], $productOverrides));
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'status' => 'posted']);
        $line = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_supplier_id' => $setting->id,
            'quantity' => $quantity,
            'purchase_amount' => bcmul($quantity, '100', 4),
        ]);
        $lot = InventoryLot::factory()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'purchase_receipt_line_id' => $line->id,
            'lot_number' => 'LOT-'.$sku,
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
            'unit_purchase_price' => '100',
        ]);

        return compact('product', 'lot', 'admin');
    }

    /** @return array<string, string> */
    private function checkoutInput(array $overrides = []): array
    {
        return array_replace([
            'shipping_name' => '配送 太郎',
            'shipping_postal_code' => '100-0001',
            'shipping_prefecture' => '東京都',
            'shipping_address_line1' => '千代田区千代田1-1',
            'shipping_address_line2' => 'テストビル101',
        ], $overrides);
    }

    public function test_shop_lists_only_public_confirmed_priced_products_and_filters(): void
    {
        $sellable = $this->sellableProduct();
        $category = Category::factory()->create(['name' => '飲料']);
        $sellable['product']->categories()->attach($category);
        Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'private', 'default_sale_price' => 100]);
        Product::factory()->create(['registration_status' => 'provisional', 'publication_status' => 'public', 'default_sale_price' => 100]);
        Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => null]);

        $this->get('/shop?q=公開テスト&category_id='.$category->id.'&availability=available')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shop/Index')
                ->where('products.total', 1)
                ->where('products.data.0.id', $sellable['product']->id)
                ->where('products.data.0.available_quantity', 10)
                ->where('products.data.0.categories.0.name', '飲料'));

        $sellable['lot']->update(['current_quantity' => 0, 'status' => 'depleted']);
        $this->get('/shop?availability=sold_out')->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)
            ->where('products.data.0.id', $sellable['product']->id));
    }

    public function test_guest_can_add_accumulate_update_and_remove_cart_items(): void
    {
        $context = $this->sellableProduct(quantity: '10');

        $this->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '2'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '3'])->assertSessionHasNoErrors();
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->component('Cart/Index')
            ->where('items.0.product.id', $context['product']->id)
            ->where('items.0.quantity', '5.0000')
            ->where('items.0.amount', '1502.5000')
            ->where('total', '1502.5000')
            ->where('cart.count', 1));

        $this->patch('/cart/'.$context['product']->id, ['quantity' => '4'])->assertSessionHasNoErrors();
        $this->assertSame('4', session('cart')[$context['product']->id]);
        $this->delete('/cart/'.$context['product']->id)->assertSessionHasNoErrors();
        $this->assertSame([], session('cart'));
    }

    public function test_cart_rejects_unavailable_invalid_scale_and_excess_quantity(): void
    {
        $context = $this->sellableProduct(quantity: '5', quantityScale: 0);
        $this->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '1.5'])
            ->assertSessionHasErrors(['quantity' => 'この商品の数量は整数で入力してください。']);
        $this->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '6'])
            ->assertSessionHasErrors(['quantity' => '在庫数（5）を超える数量はカートに入れられません。']);

        $context['product']->update(['publication_status' => 'private']);
        $this->post('/cart', ['product_id' => $context['product']->id, 'quantity' => '1'])
            ->assertSessionHasErrors(['product_id' => '現在購入できる商品を選び直してください。']);
        $this->assertNull(session('cart'));
    }

    public function test_checkout_requires_verified_customer_and_rejects_admin(): void
    {
        $context = $this->sellableProduct();
        $cart = [$context['product']->id => '1'];

        $this->withSession(['cart' => $cart])->get('/checkout')->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->withSession(['cart' => $cart])->get('/checkout')->assertRedirect('/email/verify');
        $this->actingAs(User::factory()->admin()->create())->withSession(['cart' => $cart])->get('/checkout')->assertForbidden();
        $this->post('/checkout', ['notes' => null])->assertForbidden();
    }

    public function test_customer_checkout_creates_confirmed_order_uses_current_price_and_clears_cart(): void
    {
        $context = $this->sellableProduct(quantity: '10');
        $customer = User::factory()->create(['name' => '購入者']);
        $context['product']->update(['default_sale_price' => '450.2500']);

        $response = $this->actingAs($customer)->withSession(['cart' => [$context['product']->id => '3']])
            ->post('/checkout', $this->checkoutInput(['notes' => '玄関前を希望']));

        $order = Order::with('lines')->firstOrFail();
        $response->assertRedirect('/orders/'.$order->id)->assertSessionHasNoErrors();
        $this->assertSame('配送 太郎', $order->shipping_name);
        $this->assertSame('100-0001', $order->shipping_postal_code);
        $this->assertSame('東京都', $order->shipping_prefecture);
        $this->assertSame('千代田区千代田1-1', $order->shipping_address_line1);
        $this->assertSame('テストビル101', $order->shipping_address_line2);
        $this->get('/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('order.shipping_name', '配送 太郎')
            ->where('order.shipping_postal_code', '100-0001')
            ->where('order.shipping_prefecture', '東京都')
            ->where('order.shipping_address_line1', '千代田区千代田1-1')
            ->where('order.shipping_address_line2', 'テストビル101'));
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('customer', $order->order_source);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($customer->id, $order->created_by);
        $this->assertSame('1350.7500', $order->total);
        $this->assertSame('450.2500', $order->lines[0]->unit_sale_price);
        $this->assertSame('7.0000', $context['lot']->fresh()->current_quantity);
        $this->assertDatabaseCount('order_allocations', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertNull(session('cart'));
    }

    public function test_checkout_stock_change_rolls_back_order_and_preserves_cart(): void
    {
        $context = $this->sellableProduct(quantity: '5');
        $customer = User::factory()->create();
        $cart = [$context['product']->id => '5'];
        $context['lot']->update(['current_quantity' => '2']);

        $this->actingAs($customer)->withSession(['cart' => $cart])->post('/checkout', $this->checkoutInput())
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('2.0000', $context['lot']->fresh()->current_quantity);
        $this->assertSame($cart, session('cart'));
    }

    public function test_checkout_page_provides_prefectures_and_customer_name(): void
    {
        $context = $this->sellableProduct();
        $customer = User::factory()->create(['name' => '購入者']);

        $this->actingAs($customer)->withSession(['cart' => [$context['product']->id => '1']])->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Checkout/Create')
                ->has('prefectures', 47)
                ->where('prefectures.12', '東京都')
                ->where('customerName', '購入者'));
    }

    public function test_checkout_normalizes_full_width_and_unhyphenated_postal_code(): void
    {
        $context = $this->sellableProduct();

        $this->actingAs(User::factory()->create())->withSession(['cart' => [$context['product']->id => '1']])
            ->post('/checkout', $this->checkoutInput(['shipping_postal_code' => '１０００００１', 'shipping_address_line2' => '  ']))
            ->assertSessionHasNoErrors();

        $order = Order::firstOrFail();
        $this->assertSame('100-0001', $order->shipping_postal_code);
        $this->assertNull($order->shipping_address_line2);
    }

    #[TestWith(['shipping_name', '', 'お届け先のお名前を入力してください。'])]
    #[TestWith(['shipping_postal_code', '', '郵便番号を入力してください。'])]
    #[TestWith(['shipping_postal_code', '100-00011', '郵便番号は「123-4567」の形式で入力してください。'])]
    #[TestWith(['shipping_prefecture', '東京', '都道府県を選択し直してください。'])]
    #[TestWith(['shipping_address_line1', '', '市区町村・番地を入力してください。'])]
    public function test_checkout_rejects_invalid_shipping_address(string $field, string $value, string $message): void
    {
        $context = $this->sellableProduct();
        $cart = [$context['product']->id => '1'];

        $this->actingAs(User::factory()->create())->withSession(['cart' => $cart])
            ->post('/checkout', $this->checkoutInput([$field => $value]))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('10.0000', $context['lot']->fresh()->current_quantity);
        $this->assertSame($cart, session('cart'));
    }

    public function test_customer_order_history_is_scoped_to_signed_in_customer(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $own = Order::factory()->create(['customer_id' => $customer->id, 'created_by' => $customer->id, 'status' => 'confirmed']);
        $foreign = Order::factory()->create(['customer_id' => $other->id, 'created_by' => $other->id, 'status' => 'confirmed']);

        $this->actingAs($customer)->get('/orders')->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Index')
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $own->id));
        $this->get('/orders/'.$own->id)->assertInertia(fn (Assert $page) => $page->component('Orders/Show')->where('order.id', $own->id));
        $this->get('/orders/'.$foreign->id)->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/orders')->assertForbidden();
    }
}
