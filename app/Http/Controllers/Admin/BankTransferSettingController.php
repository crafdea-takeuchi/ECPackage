<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\BankTransferDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\BankTransferSettingRequest;
use App\Models\OrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BankTransferSettingController extends Controller
{
    public function edit(): Response
    {
        $settings = OrderSetting::find(1);

        return Inertia::render('Admin/Settings/BankTransfer', [
            'bank' => $settings?->bank_transfer,
            'registered' => BankTransferDetails::registered($settings?->bank_transfer),
            'fields' => BankTransferDetails::FIELDS,
            'accountTypes' => BankTransferDetails::ACCOUNT_TYPES,
            'saveUrl' => route('admin.settings.bank-transfer.update', [], false),
            'settingsUrl' => route('admin.settings.index', [], false),
            'updatedAt' => $settings?->bank_transfer_updated_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(BankTransferSettingRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            OrderSetting::current();
            $settings = OrderSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->bank_transfer = $request->validated();
            $settings->bank_transfer_updated_by = $request->user()->id;
            $settings->bank_transfer_updated_at = now();
            $settings->save();
        }, 3);

        return to_route('admin.settings.bank-transfer.edit')->with('status', '振込先を保存しました。');
    }
}
