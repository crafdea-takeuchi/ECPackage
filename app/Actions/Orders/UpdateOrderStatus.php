<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOrderStatus
{
    private const NEXT = ['confirmed' => 'preparing', 'shipped' => 'completed'];

    public function handle(Order $order, string $status): Order
    {
        return DB::transaction(function () use ($order, $status): Order {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($status === 'shipped') {
                throw ValidationException::withMessages(['status' => '発送管理で発送を確定してください。']);
            }
            if ((self::NEXT[$current->status] ?? null) !== $status) {
                throw ValidationException::withMessages(['status' => '注文状態は決められた順序で変更してください。']);
            }
            $current->status = $status;
            $current->lock_version++;
            $current->save();

            return $current;
        }, 3);
    }
}
