@extends('admin.layout')
@section('title', 'Team')
@section('content')
<div class="ad-head"><div><h1>Team</h1><p>Who can sign in to this admin, and what their role allows.</p></div></div>
<div class="ad-grid-2">
    <section class="ad-card flush">
        <table class="ad-table">
            <thead><tr><th>Person</th><th>Role</th><th>Last sign-in</th><th></th></tr></thead>
            <tbody>
                @foreach ($team as $u)
                    <tr>
                        <td><span class="ad-who"><span class="ad-avatar">{{ strtoupper(substr($u->name ?: $u->email, 0, 1)) }}</span><span><b>{{ $u->name }}</b>@if ($u->is(auth()->user())) <span class="ad-muted">(you)</span>@endif<div class="ad-muted">{{ $u->email }}</div></span></span></td>
                        <td>
                            @if ($u->is(auth()->user()))
                                {{ \App\Support\AdminRoles::label($u->admin_role) }}
                            @else
                                <form method="POST" action="{{ route('admin.team.update', $u->id) }}">@csrf
                                    <select name="admin_role" onchange="this.form.submit()" aria-label="Role for {{ $u->email }}">@foreach ($roles as $k => [$label])<option value="{{ $k }}" @selected(($u->admin_role ?: 'super_admin') === $k)>{{ $label }}</option>@endforeach</select>
                                </form>
                            @endif
                            @if ($u->disabled_at)<span class="ad-badge bad">Disabled</span>@endif
                        </td>
                        <td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                        <td style="text-align:right">
                            @unless ($u->is(auth()->user()))
                                <span class="ad-actions" style="justify-content:flex-end">
                                    <form method="POST" action="{{ route('admin.team.update', $u->id) }}" onsubmit="return confirm('Give {{ $u->email }} a new temporary password?')">@csrf<input type="hidden" name="action" value="reset"><button class="ad-btn small" type="submit">Reset password</button></form>
                                    <form method="POST" action="{{ route('admin.team.update', $u->id) }}">@csrf<input type="hidden" name="action" value="{{ $u->disabled_at ? 'enable' : 'disable' }}"><button class="ad-btn small {{ $u->disabled_at ? '' : 'danger' }}" type="submit">{{ $u->disabled_at ? 'Enable' : 'Disable' }}</button></form>
                                </span>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
    <div>
        <section class="ad-card">
            <h2>Add a team member</h2>
            <form method="POST" action="{{ route('admin.team.invite') }}" class="ad-form">@csrf
                <label>Name<input name="name" required maxlength="120" value="{{ old('name') }}"></label>
                <label>Email<input type="email" name="email" required maxlength="190" value="{{ old('email') }}"></label>
                <label>Role<select name="admin_role">@foreach ($roles as $k => [$label])<option value="{{ $k }}" @selected(old('admin_role', 'support') === $k)>{{ $label }}</option>@endforeach</select></label>
                <button class="ad-btn primary" type="submit">Add and create a temporary password</button>
            </form>
        </section>
        <section class="ad-card">
            <h2>Roles</h2>
            <ul class="ad-list">@foreach ($roles as [$label, $help])<li><span><b>{{ $label }}</b><div class="ad-muted">{{ $help }}</div></span></li>@endforeach</ul>
        </section>
    </div>
</div>
@endsection
