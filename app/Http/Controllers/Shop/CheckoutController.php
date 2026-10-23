<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Cart\LoadCart;
use App\Actions\Orders\PlaceCustomerOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function create(Request $request, LoadCart $loadCart): Response|RedirectResponse
    {
        abort_if($request->user()->isAdmin(), 403);
        $cart = $loadCart->handle($request->session()->get('cart', []));
        if ($cart['items']->isEmpty()) {
            return to_route('cart.index')->with('status', 'カートに商品を追加してください。');
        }

        return Inertia::render('Checkout/Create', $cart);
    }

    public function store(CheckoutRequest $request, PlaceCustomerOrder $placeCustomerOrder): RedirectResponse
    {
        $order = $placeCustomerOrder->handle($request->user(), $request->session()->get('cart', []), $request->validated('notes'));
        $request->session()->forget('cart');

        return to_route('orders.show', $order)->with('status', 'ご注文を受け付けました。');
    }
}
