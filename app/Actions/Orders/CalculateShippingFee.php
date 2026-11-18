<?php

namespace App\Actions\Orders;

use App\Models\OrderSetting;

class CalculateShippingFee
{
    /**
     * お届け先の都道府県が属する地域の送料（税込）を返す。
     * 送料が未設定の地域は0円、税込の商品合計が送料無料基準額以上なら0円とする。
     */
    public function handle(?OrderSetting $settings, string $prefecture, string $itemsTotal): string
    {
        $zone = ShippingZones::zoneForPrefecture($prefecture);
        $fee = $zone === null ? null : ($settings?->shipping_zone_fees[$zone] ?? null);
        if ($fee === null || $fee === '') {
            return '0.0000';
        }

        $threshold = $settings?->free_shipping_thresholds[$zone] ?? null;
        if ($threshold !== null && $threshold !== '' && bccomp($itemsTotal, (string) $threshold, 4) >= 0) {
            return '0.0000';
        }

        return bcadd((string) $fee, '0', 4);
    }

    /**
     * 注文確認画面向けに、都道府県ごとの送料をまとめて返す。
     *
     * @return array<string, string>
     */
    public function forAllPrefectures(?OrderSetting $settings, string $itemsTotal): array
    {
        $fees = [];
        foreach (ShippingZones::definitions() as $zone) {
            foreach ($zone['prefectures'] as $prefecture) {
                $fees[$prefecture] = $this->handle($settings, $prefecture, $itemsTotal);
            }
        }

        return $fees;
    }
}
