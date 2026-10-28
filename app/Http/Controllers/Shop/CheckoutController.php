<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Cart\LoadCart;
use App\Actions\Orders\CalculateTaxes;
use App\Actions\Orders\PlaceCustomerOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function create(Request $request, LoadCart $loadCart, CalculateTaxes $calculateTaxes): Response|RedirectResponse
    {
        abort_if($request->user()->isAdmin(), 403);
        $cart = $loadCart->handle($request->session()->get('cart', []));
        if ($cart['items']->isEmpty()) {
            return to_route('cart.index')->with('status', 'カートに商品を追加してください。');
        }
        $amounts = $calculateTaxes->handle($cart['items']->map(fn (array $item): array => [
            'tax_rate_name' => $item['product']->taxRate?->name,
            'tax_rate' => $item['product']->taxRate?->rate ?? '0',
            'amount' => $item['amount'],
        ]));

        return Inertia::render('Checkout/Create', [
            'items' => $cart['items'],
            'subtotal' => $amounts['subtotal'],
            'taxes' => $amounts['taxes'],
            'taxAmount' => $amounts['tax_amount'],
            'total' => $amounts['total'],
            'prefectures' => Order::PREFECTURES,
            'paymentMethods' => Order::PAYMENT_METHODS,
            'customerName' => $request->user()->name,
        ]);
    }

    public function store(CheckoutRequest $request, PlaceCustomerOrder $placeCustomerOrder): RedirectResponse
    {
        $order = $placeCustomerOrder->handle($request->user(), $request->session()->get('cart', []), $request->validated('notes'), $request->shippingAddress(), $request->validated('payment_method'));
        $request->session()->forget('cart');

        return to_route('orders.show', $order)->with('status', 'ご注文を受け付けました。');
    }
}
