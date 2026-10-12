<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::user()?->is_admin ? redirect()->route('admin.home') : view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials + ['is_admin' => true, fn ($q) => $q->whereNull('disabled_at')], $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Those details don\'t match a Store Intelligence team account.']);
        }
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('admin.home'));
    }

    public function account(): View
    {
        return view('admin.account');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->letters()->numbers()],
        ], ['current_password.current_password' => 'Your current password isn\'t right.']);
        $request->user()->forceFill(['password' => $request->input('password')])->save();
        // Sign out other sessions that used the old password.
        Auth::logoutOtherDevices($request->input('password'));
        \App\Models\AuditLog::record('admin.password_changed', null, ['by' => $request->user()->email]);

        return redirect()->route('admin.account')->with('status', 'Password changed.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
