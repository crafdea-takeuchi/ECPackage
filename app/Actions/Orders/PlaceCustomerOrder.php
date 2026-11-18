<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaceCustomerOrder
{
    public function __construct(private ConfirmOrder $confirmOrder, private CalculateTaxes $calculateTaxes, private AssignOrderNumber $assignOrderNumber, private CalculateShippingFee $calculateShippingFee) {}

    /**
     * @param  array<int|string, string|int|float>  $cart
     * @param  array{shipping_name: string, shipping_postal_code: string, shipping_prefecture: string, shipping_address_line1: string, shipping_address_line2: ?string}  $shippingAddress
     */
    public function handle(User $customer, array $cart, ?string $notes, array $shippingAddress, string $paymentMethod): Order
    {
        if ($cart === []) {
            throw ValidationException::withMessages(['cart' => 'カートに商品がありません。']);
        }

        return DB::transaction(function () use ($customer, $cart, $notes, $shippingAddress, $paymentMethod): Order {
            $products = Product::whereIn('id', array_keys($cart))
                ->where('registration_status', 'confirmed')
                ->where('publication_status', 'public')
                ->whereNotNull('default_sale_price')
                ->with(['unit:id,name,quantity_scale', 'taxRate:id,name,rate'])
                ->get()->keyBy('id');
            if ($products->count() !== count($cart)) {
                throw ValidationException::withMessages(['cart' => '購入できない商品が含まれています。カートを確認してください。']);
            }

            $order = new Order(['customer_id' => $customer->id, 'ordered_on' => today()->format('Y-m-d'), 'notes' => $notes, ...$shippingAddress, 'payment_method' => $paymentMethod]);
            $order->order_source = 'customer';
            $order->status = 'draft';
            $order->created_by = $customer->id;
            $this->assignOrderNumber->handle($order);

            $subtotal = '0';
            $lines = [];
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
                    'tax_rate_name' => $product->taxRate?->name,
                    'tax_rate' => $product->taxRate?->rate ?? '0',
                ]);
                $order->lines()->save($line);
                $lines[] = ['tax_rate_name' => $line->tax_rate_name, 'tax_rate' => $line->tax_rate, 'amount' => $amount];
            }
            $amounts = $this->calculateTaxes->handle($lines);
            $settings = OrderSetting::whereKey(1)->sharedLock()->first();
            $shippingFee = $this->calculateShippingFee->handle($settings, $shippingAddress['shipping_prefecture'], $amounts['total']);
            $paymentFee = $this->paymentFee($settings, $paymentMethod);
            $total = bcadd(bcadd($amounts['total'], $shippingFee, 4), $paymentFee, 4);
            if (bccomp($total, '999999999999999.9999', 4) === 1) {
                throw ValidationException::withMessages(['cart' => '注文金額の合計が上限を超えています。']);
            }
            $order->subtotal = $amounts['subtotal'];
            $order->tax_amount = $amounts['tax_amount'];
            $order->shipping_fee = $shippingFee;
            $order->payment_fee = $paymentFee;
            $order->total = $total;
            $order->save();

            return $this->confirmOrder->handle($order, $customer);
        }, 3);
    }

    /**
     * 代引きの場合は注文確定時点の代引き手数料（税込）を返す。未設定は手数料なしとして扱う。
     */
    private function paymentFee(?OrderSetting $settings, string $paymentMethod): string
    {
        if ($paymentMethod !== 'cash_on_delivery') {
            return '0.0000';
        }

        return bcadd((string) ($settings?->cash_on_delivery_fee ?? '0'), '0', 4);
    }

    private function decimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($value, '.'), 1), '0'));
    }
}
