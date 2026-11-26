<?php

namespace App\Http\Middleware;

use App\Models\OrderSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApplySystemSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = 'EC02';
        $googleAnalyticsId = null;

        try {
            $saved = OrderSetting::query()->whereKey(1)->first(['system_name', 'google_analytics_id']);
            if (is_string($saved?->system_name) && trim($saved->system_name) !== '') {
                $name = $saved->system_name;
            }
            $googleAnalyticsId = $saved?->google_analytics_id ?: null;
        } catch (Throwable) {
            // Keep the fallback settings while the database is unavailable or being migrated.
        }

        config([
            'app.name' => $name,
            'mail.from.name' => $name,
            'services.google_analytics.id' => $googleAnalyticsId,
        ]);

        return $next($request);
    }
}
