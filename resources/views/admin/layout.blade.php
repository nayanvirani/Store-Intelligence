<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · Store Intelligence Admin</title>
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body>
@auth
    @php
        $me = auth()->user();
        $openTickets = \App\Support\AdminRoles::can($me, 'support') ? \App\Models\Support\Ticket::where('status', 'open')->count() : 0;
        $newMessages = \App\Support\AdminRoles::can($me, 'support') ? \App\Models\ContactSubmission::where('status', 'new')->count() : 0;
        $nav = [
            null => [['admin.home', 'Dashboard', 'home', 'dashboard']],
            'Customers' => [['admin.stores', 'Stores', 'store', 'stores'], ['admin.tickets', 'Support', 'message', 'support'], ['admin.messages', 'Website messages', 'mail', 'support']],
            'Billing' => [['admin.plans', 'Plans & features', 'card', 'plans'], ['admin.finance', 'Finance', 'chart', 'finance']],
            'Product' => [['admin.flags', 'Feature flags', 'flag', 'flags']],
            'Operations' => [['admin.sync', 'Data sync', 'chart', 'failures'], ['admin.failures', 'Failed jobs', 'alert', 'failures'], ['admin.audit', 'Audit log', 'list', 'audit']],
            'Admin' => [['admin.email', 'Email providers', 'mail', 'email'], ['admin.legal', 'Legal & policies', 'doc', 'legal'], ['admin.settings', 'Platform settings', 'settings', 'settings'], ['admin.team', 'Team', 'users', 'team']],
        ];
    @endphp
    <div class="ad-app">
        <aside class="ad-side">
            <a class="ad-brand" href="{{ route('admin.home') }}"><img src="/brand/si-icon.svg" alt="" width="30" height="30"><span>Store Intelligence<small>Internal admin</small></span></a>
            @foreach ($nav as $group => $items)
                @php($items = array_filter($items, fn ($i) => \App\Support\AdminRoles::can($me, $i[3])))
                @continue(! $items)
                @if ($group)<h6>{{ $group }}</h6>@endif
                @foreach ($items as [$route, $label, $icon])
                    <a class="ad-link" href="{{ route($route) }}" @if (request()->routeIs($route, $route.'.*', rtrim($route, 's'), rtrim($route, 's').'.*')) aria-current="page" @endif>
                        <x-admin.icon :name="$icon" /><span>{{ $label }}</span>
                        @if ($route === 'admin.tickets' && $openTickets)<span class="ad-count">{{ $openTickets }}</span>@endif
                        @if ($route === 'admin.messages' && $newMessages)<span class="ad-count">{{ $newMessages }}</span>@endif
                    </a>
                @endforeach
            @endforeach
            <div class="ad-side-foot">
                <a href="{{ route('admin.account') }}">{{ $me->name ?: $me->email }}</a>
                <div style="color:#8f91b5">{{ \App\Support\AdminRoles::label($me->admin_role) }}</div>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf<button type="submit">Sign out</button></form>
            </div>
        </aside>
        <main class="ad-main">
            @if (session('status'))<div class="ad-flash">✓ {{ session('status') }}</div>@endif
            @if ($errors->any() && ! request()->routeIs('admin.login'))<div class="ad-flash bad">{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>
@else
    @yield('content')
@endauth
</body>
</html>
