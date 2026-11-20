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
            'cart' => ['count' => count($request->session()->get('cart', []))],
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ];
    }
}
