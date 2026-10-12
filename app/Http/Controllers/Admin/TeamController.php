<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AdminRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Store Intelligence team: who can sign in to the Internal Admin, and with which role.
 */
class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.team', ['team' => User::where('is_admin', true)->orderByRaw('disabled_at is not null')->orderBy('name')->get(), 'roles' => AdminRoles::ROLES]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'admin_role' => ['required', Rule::in(array_keys(AdminRoles::ROLES))],
        ]);
        $password = Str::password(16, symbols: false);
        $user = User::forceCreate($data + ['password' => $password, 'is_admin' => true]);
        AuditLog::record('admin.team_added', null, ['email' => $user->email, 'role' => $user->admin_role, 'by' => $request->user()->email]);

        return back()->with('status', "Added {$user->email}. Temporary password: {$password} (share it privately; they can change it under their account).");
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $user = User::where('is_admin', true)->findOrFail($user);
        $data = $request->validate(['admin_role' => ['nullable', Rule::in(array_keys(AdminRoles::ROLES))], 'action' => ['nullable', 'in:disable,enable,reset']]);
        if ($user->is($request->user()) && $request->input('action') !== null) {
            return back()->withErrors(['team' => 'You can\'t disable or reset your own account here.']);
        }

        $message = 'Saved.';
        if (! empty($data['admin_role']) && $data['admin_role'] !== $user->admin_role) {
            if ($user->is($request->user())) {
                return back()->withErrors(['team' => 'You can\'t change your own role.']);
            }
            $user->forceFill(['admin_role' => $data['admin_role']])->save();
            $message = "{$user->email} is now ".AdminRoles::label($user->admin_role).'.';
        }
        if (($data['action'] ?? null) === 'disable') {
            $user->forceFill(['disabled_at' => now(), 'remember_token' => Str::random(60)])->save();
            $message = "{$user->email} can no longer sign in.";
        } elseif (($data['action'] ?? null) === 'enable') {
            $user->forceFill(['disabled_at' => null])->save();
            $message = "{$user->email} can sign in again.";
        } elseif (($data['action'] ?? null) === 'reset') {
            $password = Str::password(16, symbols: false);
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $message = "New temporary password for {$user->email}: {$password}";
        }
        AuditLog::record('admin.team_updated', null, ['email' => $user->email, 'role' => $user->admin_role, 'action' => $data['action'] ?? null, 'by' => $request->user()->email]);

        return back()->with('status', $message);
    }
}
