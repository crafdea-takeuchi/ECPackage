<?php

namespace App\Actions\Orders;

use App\Models\OrderSetting;
use App\Models\PostalCodeShippingGroup;
use App\Models\PostalCodeShippingPostalCode;

class CalculateShippingFee
{
    /**
     * お届け先の送料（税込）を返す。
     * 郵便番号が郵便番号別送料グループに登録されていれば、そのグループの送料と送料無料基準額だけで判定し、
     * 都道府県別の送料は参照しない。登録がなければ都道府県が属する地域の送料で判定する。
     */
    public function handle(?OrderSetting $settings, string $prefecture, string $itemsTotal, ?string $postalCode = null): string
    {
        $group = $this->postalCodeGroup($postalCode);
        if ($group !== null) {
            return $this->applyFreeShipping((string) $group->shipping_fee, $group->free_shipping_threshold, $itemsTotal);
        }

        return $this->forPrefecture($settings, $prefecture, $itemsTotal);
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
                $fees[$prefecture] = $this->forPrefecture($settings, $prefecture, $itemsTotal);
            }
        }

        return $fees;
    }

    /**
     * 注文確認画面向けに、郵便番号別送料グループに登録された郵便番号（7桁）ごとの送料をまとめて返す。
     *
     * @return array<string, string>
     */
    public function forAllGroupPostalCodes(string $itemsTotal): array
    {
        $fees = [];
        PostalCodeShippingGroup::query()->with('postalCodes:id,postal_code_shipping_group_id,postal_code')->get()
            ->each(function (PostalCodeShippingGroup $group) use (&$fees, $itemsTotal): void {
                $fee = $this->applyFreeShipping((string) $group->shipping_fee, $group->free_shipping_threshold, $itemsTotal);
                foreach ($group->postalCodes as $postalCode) {
                    $fees[$postalCode->postal_code] = $fee;
                }
            });

        return $fees;
    }

    /**
     * 都道府県が属する地域の送料を返す。送料が未設定の地域は0円とする。
     */
    private function forPrefecture(?OrderSetting $settings, string $prefecture, string $itemsTotal): string
    {
        $zone = ShippingZones::zoneForPrefecture($prefecture);
        $fee = $zone === null ? null : ($settings?->shipping_zone_fees[$zone] ?? null);
        if ($fee === null || $fee === '') {
            return '0.0000';
        }

        return $this->applyFreeShipping((string) $fee, $settings?->free_shipping_thresholds[$zone] ?? null, $itemsTotal);
    }

    /**
     * 税込の商品合計が送料無料基準額以上なら0円、それ以外は送料を返す。
     */
    private function applyFreeShipping(string $fee, ?string $threshold, string $itemsTotal): string
    {
        if ($threshold !== null && $threshold !== '' && bccomp($itemsTotal, $threshold, 4) >= 0) {
            return '0.0000';
        }

        return bcadd($fee, '0', 4);
    }

    private function postalCodeGroup(?string $postalCode): ?PostalCodeShippingGroup
    {
        $normalized = PostalCodeShippingPostalCode::normalize($postalCode);
        if ($normalized === null) {
            return null;
        }

        return PostalCodeShippingPostalCode::query()->where('postal_code', $normalized)->with('group')->first()?->group;
    }
}
