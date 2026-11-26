<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role', 'email_verified_at')],
            'adminSettingsUrl' => $request->user()?->isAdmin() ? route('admin.settings.index', [], false) : null,
            'systemName' => config('app.name'),
            'googleAnalyticsId' => $this->googleAnalyticsId($request),
            'cart' => ['count' => count($request->session()->get('cart', []))],
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ];
    }

    /**
     * お客様向け画面にだけ測定IDを渡す。管理画面と、管理者がお客様側を確認しているアクセスは計測しない。
     */
    private function googleAnalyticsId(Request $request): ?string
    {
        if ($request->is('admin', 'admin/*') || $request->user()?->isAdmin()) {
            return null;
        }

        return config('services.google_analytics.id') ?: null;
    }
}
