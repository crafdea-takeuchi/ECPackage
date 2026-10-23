<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/OrderSettings/Edit', ['settings' => OrderSetting::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cancellable_statuses' => ['present', 'array', 'max:3'],
            'cancellable_statuses.*' => ['string', 'distinct', Rule::in(['draft', 'confirmed', 'preparing'])],
        ]);
        OrderSetting::current()->update($data);

        return back()->with('status', '注文設定を保存しました。');
    }
}
