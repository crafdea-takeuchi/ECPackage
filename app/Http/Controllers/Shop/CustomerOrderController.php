<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Orders\CalculateTaxes;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerOrderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_if($request->user()->isAdmin(), 403);

        return Inertia::render('Orders/Index', [
            'orders' => Order::whereBelongsTo($request->user(), 'customer')->withCount('lines')->latest('ordered_on')->latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, Order $order, CalculateTaxes $calculateTaxes): Response
    {
        abort_if($request->user()->isAdmin(), 403);
        abort_unless($order->customer_id === $request->user()->id, 404);
        $order->load(['lines.components', 'lines.backorders', 'shipments' => fn ($query) => $query->where('status', 'shipped')->with('lines')]);
        $amounts = $calculateTaxes->handle($order->lines->map(fn (OrderLine $line): array => [
            'tax_rate_name' => $line->tax_rate_name,
            'tax_rate' => $line->tax_rate,
            'amount' => $line->line_amount,
        ]));

        return Inertia::render('Orders/Show', ['order' => $order, 'taxes' => $amounts['taxes']]);
    }
}
