<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Cart\LoadCart;
use App\Actions\Orders\CalculateTaxes;
use App\Http\Controllers\Controller;
use App\Http\Requests\CartItemRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function index(LoadCart $loadCart, CalculateTaxes $calculateTaxes): Response
    {
        $cart = $loadCart->handle(session('cart', []));
        $amounts = $calculateTaxes->handle($cart['items']->map(fn (array $item): array => [
            'tax_rate_name' => $item['product']->taxRate?->name,
            'tax_rate' => $item['product']->taxRate?->rate ?? '0',
            'amount' => $item['amount'],
        ]));

        return Inertia::render('Cart/Index', [
            'items' => $cart['items'],
            'subtotal' => $amounts['subtotal'],
            'taxes' => $amounts['taxes'],
            'taxAmount' => $amounts['tax_amount'],
            'total' => $amounts['total'],
        ]);
    }

    public function store(CartItemRequest $request): RedirectResponse
    {
        $product = Product::findOrFail($request->integer('product_id'));
        $cart = session('cart', []);
        $quantity = bcadd((string) ($cart[$product->id] ?? '0'), (string) $request->validated('quantity'), 4);
        $this->ensureAvailable($product, $quantity);
        $cart[$product->id] = $quantity;
        session(['cart' => $cart]);

        return back()->with('status', 'カートに追加しました。');
    }

    public function update(CartItemRequest $request, Product $product): RedirectResponse
    {
        $quantity = (string) $request->validated('quantity');
        $this->ensureAvailable($product, $quantity);
        $cart = session('cart', []);
        $cart[$product->id] = $quantity;
        session(['cart' => $cart]);

        return back()->with('status', 'カートの数量を変更しました。');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $cart = session('cart', []);
        unset($cart[$product->id]);
        session(['cart' => $cart]);

        return back()->with('status', 'カートから商品を削除しました。');
    }

    private function ensureAvailable(Product $product, string $quantity): void
    {
        $available = $product->availableQuantity();
        if ($product->shortage_policy !== 'backorder' && bccomp($quantity, $available, 4) === 1) {
            throw ValidationException::withMessages(['quantity' => "在庫数（{$available}）を超える数量はカートに入れられません。"]);
        }
    }
}
