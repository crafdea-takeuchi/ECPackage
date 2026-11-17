<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Cart\LoadCart;
use App\Actions\Orders\BankTransferDetails;
use App\Actions\Orders\CalculateTaxes;
use App\Actions\Orders\PlaceCustomerOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\OrderSetting;
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

        $settings = OrderSetting::find(1);
        $bank = $settings?->bank_transfer;
        $bankTransfer = BankTransferDetails::registered($bank)
            ? array_intersect_key($bank, array_flip(['bank_name', 'branch_name', 'account_type', 'account_number', 'account_holder_kana', 'instructions']))
            : null;

        return Inertia::render('Checkout/Create', [
            'bankTransfer' => $bankTransfer,
            'items' => $cart['items'],
            'subtotal' => $amounts['subtotal'],
            'taxes' => $amounts['taxes'],
            'taxAmount' => $amounts['tax_amount'],
            'total' => $amounts['total'],
            'prefectures' => Order::PREFECTURES,
            'paymentMethods' => Order::PAYMENT_METHODS,
            'paymentFees' => ['cash_on_delivery' => $settings?->cash_on_delivery_fee ?? '0.0000'],
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
