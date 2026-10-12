<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreUser;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Support\Spa\Page;

class UsersController extends Controller
{
    public function index(Request $request, Store $store): Page
    {
        $me = $request->attributes->get('storeUser');
        $users = $store->users()->orderByRaw('disabled_at is not null')->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('created_at')->get();

        return page('settings/users', [
            'users' => $users->map(fn (StoreUser $u) => [
                'id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email, 'role' => $u->role, 'status' => $u->status(),
                'me' => $u->is($me), 'account_owner' => (bool) $u->account_owner, 'pending' => $u->isPendingInvite(),
                'disabled' => $u->disabled_at !== null, 'last_active_at' => $u->last_active_at,
                'locked' => $u->is($me) || $u->account_owner || ($u->role === 'owner' && $me->role !== 'owner'),
            ]),
            'assignable' => Permissions::assignableBy($me->role),
            'roles' => Permissions::ROLES,
            'matrix' => array_values(Permissions::MATRIX),
        ]);
    }

    public function invite(Request $request, Store $store): RedirectResponse
    {
        $me = $request->attributes->get('storeUser');

        $validator = Validator::make($request->all(), [
            'email' => 'required|email:rfc|max:190',
            'role' => 'required|in:'.implode(',', Permissions::assignableBy($me->role)),
        ]);
        if ($validator->fails()) {
            return $this->back($validator->errors()->has('email') ? 'invalid_email' : 'permission');
        }

        $email = strtolower($request->input('email'));
        $existing = $store->users()->whereRaw('lower(email) = ?', [$email])->first();
        if ($existing && $existing->disabled_at === null) {
            return $this->back('already_member');
        }

        if (! app(\App\Services\Usage::class)->allows($store, 'team_members')) {
            return $this->back('team_limit');
        }

        $user = $existing ?? new StoreUser(['store_id' => $store->id, 'email' => $email]);
        $user->fill(['role' => $request->input('role'), 'invited_at' => now(), 'invited_by' => $me->id, 'disabled_at' => null])->save();

        AuditLog::record('user.invited', $store, ['email' => $email, 'role' => $user->role], $user);

        return $this->back('invited');
    }

    public function updateRole(Request $request, Store $store, StoreUser $user): RedirectResponse
    {
        $me = $request->attributes->get('storeUser');
        abort_unless($user->store_id === $store->id, 404);

        $role = (string) $request->input('role');

        if ($user->is($me)) {
            return $this->back('self');
        }
        // Admins can't change owners, and can only hand out roles they may assign.
        if (! in_array($role, Permissions::assignableBy($me->role), true) || ($user->role === 'owner' && $me->role !== 'owner')) {
            return $this->back('permission');
        }
        if ($user->account_owner && $role !== 'owner') {
            return $this->back('last_owner');
        }
        if ($user->role === 'owner' && $role !== 'owner' && $this->isLastOwner($store, $user)) {
            return $this->back('last_owner');
        }

        $from = $user->role;
        $user->forceFill(['role' => $role])->save();
        AuditLog::record('user.role_changed', $store, ['from' => $from, 'to' => $role], $user);

        return $this->back('role_changed');
    }

    public function remove(Request $request, Store $store, StoreUser $user): RedirectResponse
    {
        $me = $request->attributes->get('storeUser');
        abort_unless($user->store_id === $store->id, 404);

        if ($user->is($me)) {
            return $this->back('self');
        }
        if ($user->role === 'owner' && $me->role !== 'owner') {
            return $this->back('permission');
        }
        if ($user->account_owner || ($user->role === 'owner' && $this->isLastOwner($store, $user))) {
            return $this->back('last_owner');
        }

        if ($user->isPendingInvite()) {
            $user->delete();
            AuditLog::record('user.invite_cancelled', $store, ['email' => $user->email]);

            return $this->back('deleted');
        }

        $user->forceFill(['disabled_at' => now()])->save();
        AuditLog::record('user.access_removed', $store, [], $user);

        return $this->back('access_removed');
    }

    public function restore(Request $request, Store $store, StoreUser $user): RedirectResponse
    {
        abort_unless($user->store_id === $store->id, 404);

        if ($user->role === 'owner' && $request->attributes->get('storeUser')->role !== 'owner') {
            return $this->back('permission');
        }
        if (! app(\App\Services\Usage::class)->allows($store, 'team_members')) {
            return $this->back('team_limit');
        }

        $user->forceFill(['disabled_at' => null])->save();
        AuditLog::record('user.access_restored', $store, [], $user);

        return $this->back('access_restored');
    }

    private function isLastOwner(Store $store, StoreUser $user): bool
    {
        return ! $store->activeOwners()->whereKeyNot($user->id)->exists();
    }

    private function back(string $notice): RedirectResponse
    {
        return redirect()->to(app_route('app.settings.users', ['notice' => $notice]));
    }
}
