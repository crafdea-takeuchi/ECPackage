<?php

namespace App\Actions\Orders;

use App\Models\Order;

class ShippingZones
{
    public const LABELS = [
        'hokkaido' => '北海道',
        'tohoku' => '東北',
        'kanto_shinetsu' => '関東・信越',
        'hokuriku_chubu' => '北陸・中部',
        'kansai_chugoku' => '関西・中国',
        'shikoku' => '四国',
        'kyushu' => '九州',
        'okinawa' => '沖縄',
    ];

    /**
     * 既存のOrder::PREFECTURESは都道府県コード順（1〜47）。
     * 県名一覧は複製せず、対応するコードで参照する。
     */
    private const PREFECTURE_CODES = [
        'hokkaido' => [1],
        'tohoku' => [2, 3, 4, 5, 6, 7],
        'kanto_shinetsu' => [8, 9, 10, 11, 12, 13, 14, 19, 20],
        'hokuriku_chubu' => [15, 16, 17, 18, 21, 22, 23, 24],
        'kansai_chugoku' => [25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35],
        'shikoku' => [36, 37, 38, 39],
        'kyushu' => [40, 41, 42, 43, 44, 45, 46],
        'okinawa' => [47],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return list<array{code: string, name: string, prefectures: list<string>}> */
    public static function definitions(): array
    {
        $zones = [];
        foreach (self::LABELS as $code => $name) {
            $zones[] = ['code' => $code, 'name' => $name, 'prefectures' => array_map(
                fn (int $prefectureCode): string => Order::PREFECTURES[$prefectureCode - 1],
                self::PREFECTURE_CODES[$code],
            )];
        }

        return $zones;
    }

    public static function configuredCount(?array $fees): int
    {
        return count(array_filter(self::codes(), fn (string $code): bool => isset($fees[$code]) && $fees[$code] !== ''));
    }
}
