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

        try {
            $saved = OrderSetting::query()->whereKey(1)->value('system_name');
            if (is_string($saved) && trim($saved) !== '') {
                $name = $saved;
            }
        } catch (Throwable) {
            // Keep the fallback name while the database is unavailable or being migrated.
        }

        config([
            'app.name' => $name,
            'mail.from.name' => $name,
        ]);

        return $next($request);
    }
}
