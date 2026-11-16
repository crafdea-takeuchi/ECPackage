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
        $details = OrderSetting::find(1)?->bank_transfer;
        $registered = BankTransferDetails::registered($details);

        return Inertia::render('Admin/Settings/Index', [
            'cards' => [
                ['title' => '振込先設定', 'description' => '銀行振込でお客様へ案内する振込先を登録します。',
                    'status' => $registered ? '振込先設定済み：'.$details['bank_name'].' '.$details['branch_name'] : '振込先が設定されていません。銀行振込の注文受付前に登録してください。',
                    'warning' => ! $registered,
                    'account_number' => $registered ? BankTransferDetails::maskedNumber($details['account_number']) : null,
                    'href' => route('admin.settings.bank-transfer.edit', [], false), 'button' => '設定を開く'],
            ],
        ]);
    }
}
