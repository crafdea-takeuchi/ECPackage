<?php

namespace Tests\Feature;

use App\Actions\Orders\AssignOrderNumber;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AssignOrderNumberTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  list<string>  $sequence
     */
    private function assignerReturning(array $sequence): AssignOrderNumber
    {
        return new class($sequence) extends AssignOrderNumber
        {
            /** @param list<string> $sequence */
            public function __construct(private array $sequence) {}

            protected function randomCharacters(): string
            {
                return array_shift($this->sequence) ?? 'ZZZZ';
            }
        };
    }

    private function draftOrder(): Order
    {
        return Order::factory()->make(['order_number' => null]);
    }

    public function test_order_number_is_on_plus_japan_year_month_plus_four_alphanumerics(): void
    {
        $this->travelTo('2026-11-30 16:00:00');

        $order = app(AssignOrderNumber::class)->handle($this->draftOrder());

        $this->assertMatchesRegularExpression('/\AON2612[A-HJ-NP-Z2-9]{4}\z/', $order->order_number);
        $this->assertSame(10, strlen($order->order_number));
        $this->assertTrue($order->exists);
    }

    public function test_random_part_never_contains_ambiguous_characters(): void
    {
        $this->assertSame(32, strlen(AssignOrderNumber::CHARACTERS));
        foreach (['0', 'O', '1', 'I'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, AssignOrderNumber::CHARACTERS);
        }

        $assigner = app(AssignOrderNumber::class);
        for ($index = 0; $index < 50; $index++) {
            $number = $assigner->handle($this->draftOrder())->order_number;
            $this->assertDoesNotMatchRegularExpression('/[0O1I]/', substr($number, 6));
        }
    }

    public function test_existing_order_number_is_skipped_and_regenerated(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        Order::factory()->create(['order_number' => 'ON2611AAAA']);

        $order = $this->assignerReturning(['AAAA', 'B2C3'])->handle($this->draftOrder());

        $this->assertSame('ON2611B2C3', $order->order_number);
        $this->assertSame(1, Order::where('order_number', 'ON2611AAAA')->count());
    }

    public function test_numbering_fails_instead_of_saving_a_duplicate_when_attempts_are_exhausted(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        Order::factory()->create(['order_number' => 'ON2611ZZZZ']);

        try {
            $this->assignerReturning([])->handle($this->draftOrder());
            $this->fail('重複する注文番号で保存してはいけません。');
        } catch (RuntimeException) {
            $this->assertSame(1, Order::count());
        }
    }

    public function test_customer_checkout_and_admin_creation_use_the_new_format(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $product = Product::factory()->create([
            'registration_status' => 'confirmed', 'publication_status' => 'public',
            'default_sale_price' => 100, 'shortage_policy' => 'reject',
        ]);
        InventoryLot::factory()->create(['product_id' => $product->id, 'current_quantity' => 10]);
        $customer = User::factory()->create();

        $this->actingAs($customer)->withSession(['cart' => [$product->id => '1']])->post('/checkout', [
            'notes' => null, 'payment_method' => 'cash_on_delivery', 'shipping_name' => '購入者',
            'shipping_postal_code' => '100-0001', 'shipping_prefecture' => '東京都',
            'shipping_address_line1' => '千代田区1-1', 'shipping_address_line2' => null,
        ])->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->admin()->create())->post('/admin/orders', [
            'customer_id' => $customer->id, 'ordered_on' => '2026-11-17', 'notes' => null, 'action' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_sale_price' => '100']],
        ])->assertSessionHasNoErrors();

        $numbers = Order::orderBy('id')->pluck('order_number');
        $this->assertCount(2, $numbers);
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/\AON2611[A-HJ-NP-Z2-9]{4}\z/', $number);
        }
        $this->assertNotSame($numbers[0], $numbers[1]);
    }
}
