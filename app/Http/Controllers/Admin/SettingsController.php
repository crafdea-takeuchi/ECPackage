<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\BankTransferDetails;
use App\Actions\Orders\ShippingZones;
use App\Http\Controllers\Controller;
use App\Models\OrderSetting;
use App\Models\PostalCodeShippingGroup;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $settings = OrderSetting::find(1);
        $details = $settings?->bank_transfer;
        $fee = $settings?->cash_on_delivery_fee;
        $registered = BankTransferDetails::registered($details);
        $count = ShippingZones::configuredCount($settings?->shipping_zone_fees);
        $shippingStatus = $count === 0 ? '送料が設定されていません' : ($count === 8 ? '8地域設定済み' : $count.'/8地域設定済み');
        if ($count > 0) {
            $thresholdCount = ShippingZones::configuredCount($settings?->free_shipping_thresholds);
            $shippingStatus .= "\n地域別送料無料基準：".($thresholdCount === 0 ? '設定なし' : $thresholdCount.'地域設定済み');
        }
        $postalCodeGroupCount = PostalCodeShippingGroup::query()->count();
        $shippingStatus .= "\n郵便番号別送料グループ：".($postalCodeGroupCount === 0 ? '設定なし' : $postalCodeGroupCount.'件設定済み');

        return Inertia::render('Admin/Settings/Index', [
            'cards' => [
                ['title' => '振込先設定', 'description' => '銀行振込でお客様へ案内する振込先を登録します。',
                    'status' => $registered ? '振込先設定済み：'.$details['bank_name'].' '.$details['branch_name'] : '振込先が設定されていません。銀行振込の注文受付前に登録してください。',
                    'warning' => ! $registered,
                    'account_number' => $registered ? BankTransferDetails::maskedNumber($details['account_number']) : null,
                    'href' => route('admin.settings.bank-transfer.edit', [], false), 'button' => '設定を開く'],
                ['title' => '手数料設定', 'description' => '代引きなど、支払方法に応じた手数料を設定します。',
                    'status' => '現在の代引き手数料：'.($fee === null ? '未設定' : number_format((float) $fee, 0).'円'.(bccomp($fee, '0', 4) === 0 ? '（無料）' : '')),
                    'href' => route('admin.settings.fees.edit', [], false), 'button' => '設定を開く'],
                ['title' => '送料設定', 'description' => '発送先の地域ごとの送料と、送料無料になる購入金額を設定します。',
                    'status' => $shippingStatus, 'warning' => $count !== 8,
                    'href' => route('admin.settings.shipping.edit', [], false), 'button' => '設定を開く'],
                ['title' => 'システム名設定', 'description' => '画面と通知メールに表示するシステム名を設定します。',
                    'status' => '現在のシステム名：'.($settings?->system_name ?? 'EC02'),
                    'href' => route('admin.settings.system-name.edit', [], false), 'button' => '設定を開く'],
            ],
        ]);
    }
}
