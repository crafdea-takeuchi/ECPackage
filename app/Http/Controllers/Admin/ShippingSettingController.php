<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\ShippingZones;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingSettingRequest;
use App\Models\OrderSetting;
use App\Models\PostalCodeShippingGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ShippingSettingController extends Controller
{
    public function edit(): Response
    {
        $settings = OrderSetting::find(1);

        return Inertia::render('Admin/Settings/Shipping', [
            'zones' => ShippingZones::definitions(),
            'shippingZoneFees' => $settings?->shipping_zone_fees,
            'freeShippingThresholds' => $settings?->free_shipping_thresholds,
            'postalCodeGroups' => PostalCodeShippingGroup::query()->with('postalCodes:id,postal_code_shipping_group_id,postal_code')->orderBy('id')->get()->map(fn (PostalCodeShippingGroup $group): array => [
                'id' => $group->id, 'name' => $group->name, 'shipping_fee' => $group->shipping_fee,
                'free_shipping_threshold' => $group->free_shipping_threshold,
                'postal_codes' => $group->postalCodes->pluck('postal_code')->all(),
                'update_url' => route('admin.settings.shipping.postal-code-groups.update', $group, false),
                'delete_url' => route('admin.settings.shipping.postal-code-groups.destroy', $group, false),
            ]),
            'postalCodeGroupStoreUrl' => route('admin.settings.shipping.postal-code-groups.store', [], false),
            'maxAmount' => (string) ShippingSettingRequest::MAX_AMOUNT,
            'saveUrl' => route('admin.settings.shipping.update', [], false),
            'settingsUrl' => route('admin.settings.index', [], false),
        ]);
    }

    public function update(ShippingSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            OrderSetting::current();
            $settings = OrderSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $fees = [];
            foreach (ShippingZones::codes() as $code) {
                $fees[$code] = bcadd((string) $data['shipping_zone_fees'][$code], '0', 4);
            }
            $settings->shipping_zone_fees = $fees;
            if (array_key_exists('free_shipping_thresholds', $data)) {
                $thresholds = array_replace(
                    array_fill_keys(ShippingZones::codes(), null),
                    array_intersect_key($settings->free_shipping_thresholds ?? [], ShippingZones::LABELS),
                );
                foreach ($data['free_shipping_thresholds'] as $code => $value) {
                    $thresholds[$code] = $value === null ? null : bcadd((string) $value, '0', 4);
                }
                $settings->free_shipping_thresholds = $thresholds;
            }
            $settings->save();
        }, 3);

        return to_route('admin.settings.shipping.edit')->with('status', '送料設定を保存しました。');
    }
}
