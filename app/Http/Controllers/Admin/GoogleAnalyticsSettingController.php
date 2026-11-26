<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleAnalyticsSettingRequest;
use App\Models\OrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoogleAnalyticsSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/GoogleAnalytics', [
            'googleAnalyticsId' => OrderSetting::current()->google_analytics_id,
            'saveUrl' => route('admin.settings.google-analytics.update', [], false),
            'settingsUrl' => route('admin.settings.index', [], false),
        ]);
    }

    public function update(GoogleAnalyticsSettingRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            OrderSetting::current();
            $settings = OrderSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->google_analytics_id = $request->validated('google_analytics_id');
            $settings->save();
        }, 3);

        return to_route('admin.settings.google-analytics.edit')->with('status', 'Googleアナリティクスの設定を保存しました。');
    }
}
