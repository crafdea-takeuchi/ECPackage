<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'users' => User::query()->latest('id')->paginate(15)->through(fn (User $user) => $user->only('id', 'name', 'email', 'role', 'email_verified_at')),
            'counts' => ['all' => User::count(), 'customers' => User::where('role', 'customer')->count(), 'admins' => User::where('role', 'admin')->count()],
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->role = $request->validated('role');
        $user->email_verified_at = now();
        $user->save();
        event(new Registered($user));

        return redirect()->route('admin.dashboard')->with('status', 'アカウントを作成しました。');
    }
}
