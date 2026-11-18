<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class AssignOrderNumber
{
    public const PREFIX = 'ON';

    /** 見分けにくい 0/O と 1/I を除いた英大文字と数字。 */
    public const CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const RANDOM_LENGTH = 4;

    private const MAX_ATTEMPTS = 20;

    /**
     * 「ON＋YYMM（日本時間の発番年月）＋英数字4桁」の注文番号を割り当てて保存する。
     * 既存番号と重複した場合や、同時発番で一意制約に違反した場合は別の番号で再試行する。
     */
    public function handle(Order $order): Order
    {
        $prefix = self::PREFIX.now('Asia/Tokyo')->format('ym');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $prefix.$this->randomCharacters();
            if (Order::where('order_number', $candidate)->exists()) {
                continue;
            }

            $order->order_number = $candidate;
            try {
                $order->save();

                return $order;
            } catch (UniqueConstraintViolationException $exception) {
                if (! Order::where('order_number', $candidate)->exists()) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('注文番号を採番できませんでした。時間をおいて再度お試しください。');
    }

    protected function randomCharacters(): string
    {
        $characters = '';
        for ($index = 0; $index < self::RANDOM_LENGTH; $index++) {
            $characters .= self::CHARACTERS[random_int(0, strlen(self::CHARACTERS) - 1)];
        }

        return $characters;
    }
}
