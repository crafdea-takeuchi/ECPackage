<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceCustomerOrder
{
    public function __construct(private ConfirmOrder $confirmOrder) {}

    /**
     * @param  array<int|string, string|int|float>  $cart
     * @param  array{shipping_name: string, shipping_postal_code: string, shipping_prefecture: string, shipping_address_line1: string, shipping_address_line2: ?string}  $shippingAddress
     */
    public function handle(User $customer, array $cart, ?string $notes, array $shippingAddress): Order
    {
        if ($cart === []) {
            throw ValidationException::withMessages(['cart' => 'カートに商品がありません。']);
        }

        return DB::transaction(function () use ($customer, $cart, $notes, $shippingAddress): Order {
            $products = Product::whereIn('id', array_keys($cart))
                ->where('registration_status', 'confirmed')
                ->where('publication_status', 'public')
                ->whereNotNull('default_sale_price')
                ->with('unit:id,name,quantity_scale')
                ->get()->keyBy('id');
            if ($products->count() !== count($cart)) {
                throw ValidationException::withMessages(['cart' => '購入できない商品が含まれています。カートを確認してください。']);
            }

            $order = new Order(['customer_id' => $customer->id, 'ordered_on' => today()->format('Y-m-d'), 'notes' => $notes, ...$shippingAddress]);
            $order->order_number = 'O-'.Str::ulid();
            $order->order_source = 'customer';
            $order->status = 'draft';
            $order->created_by = $customer->id;
            $order->save();

            $subtotal = '0';
            foreach (collect($cart)->keys()->map(fn ($id): int => (int) $id)->sort()->values() as $index => $productId) {
                $product = $products->get($productId);
                $quantity = (string) $cart[$productId];
                if (! is_numeric($quantity) || bccomp($quantity, '0', 4) !== 1 || $this->decimalPlaces($quantity) > $product->unit->quantity_scale) {
                    throw ValidationException::withMessages(['cart' => "{$product->name} の数量を確認してください。"]);
                }
                $amount = bcmul($quantity, (string) $product->default_sale_price, 4);
                $subtotal = bcadd($subtotal, $amount, 4);
                if (bccomp($subtotal, '999999999999999.9999', 4) === 1) {
                    throw ValidationException::withMessages(['cart' => '注文金額の合計が上限を超えています。']);
                }
                $line = new OrderLine([
                    'line_number' => $index + 1,
                    'product_id' => $product->id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'unit_name' => $product->unit->name,
                    'quantity' => $quantity,
                    'unit_sale_price' => $product->default_sale_price,
                    'line_amount' => $amount,
                ]);
                $order->lines()->save($line);
            }
            $order->subtotal = $subtotal;
            $order->total = $subtotal;
            $order->save();

            return $this->confirmOrder->handle($order, $customer);
        }, 3);
    }

    private function decimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($value, '.'), 1), '0'));
    }
}
