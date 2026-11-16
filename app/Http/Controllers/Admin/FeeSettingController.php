<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeSettingRequest;
use App\Models\Order;
use App\Models\OrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FeeSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Fees', [
            'cashOnDeliveryFee' => OrderSetting::find(1)?->cash_on_delivery_fee,
            'cashOnDeliveryAvailable' => array_key_exists('cash_on_delivery', Order::PAYMENT_METHODS),
            'maxFee' => (string) FeeSettingRequest::MAX_FEE,
            'saveUrl' => route('admin.settings.fees.update', [], false),
            'settingsUrl' => route('admin.settings.index', [], false),
        ]);
    }

    public function update(FeeSettingRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            OrderSetting::current();
            $settings = OrderSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->cash_on_delivery_fee = $request->validated('cash_on_delivery_fee');
            $settings->save();
        }, 3);

        return to_route('admin.settings.fees.edit')->with('status', '手数料設定を保存しました。');
    }
}
