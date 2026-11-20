<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemNameSettingRequest;
use App\Models\OrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemNameSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/SystemName', [
            'systemName' => OrderSetting::current()->system_name,
            'saveUrl' => route('admin.settings.system-name.update', [], false),
            'settingsUrl' => route('admin.settings.index', [], false),
        ]);
    }

    public function update(SystemNameSettingRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            OrderSetting::current();
            $settings = OrderSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->system_name = $request->validated('system_name');
            $settings->save();
        }, 3);

        return to_route('admin.settings.system-name.edit')->with('status', 'システム名を保存しました。');
    }
}
