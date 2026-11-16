<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\BankTransferDetails;
use App\Http\Controllers\Controller;
use App\Models\OrderSetting;
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
            ],
        ]);
    }
}
