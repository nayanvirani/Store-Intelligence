<?php

namespace App\Support\Spa;

use App\Models\Store;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * Data every React page gets: who's signed in, what they may do, the plan, the section
 * sidebar (with the current item worked out here, from the route) and the usage-limit notice.
 */
class Shared
{
    public static function for(Request $request): array
    {
        /** @var Store|null $store */
        $store = $request->attributes->get('store');
        $user = $request->attributes->get('storeUser');
        $access = (bool) $store?->hasPlanAccess();

        return [
            'shop' => $store?->shop_domain,
            'storeName' => $store?->name,
            'currency' => $store?->currency ?? 'USD',
            'user' => $user ? ['name' => $user->first_name, 'role' => $user->role] : null,
            'can' => collect(array_keys(Permissions::MATRIX))->mapWithKeys(fn ($p) => [$p => (bool) $user?->can($p)])->all(),
            'plan' => $store?->effectivePlan(),
            // Features this store can use (a child feature counts only with its parent).
            'includes' => $store ? array_values(array_filter(array_keys(\App\Support\Modules::ALL), fn ($k) => $store->planIncludes($k))) : [],
            // For locked features: the first plan that includes each one, for "Upgrade to Growth".
            'featurePlans' => $store ? \App\Support\Plans::firstWith() : [],
            'featureLabels' => collect(\App\Support\Modules::all())->map(fn ($m) => $m[0])->all(),
            'access' => (bool) $access,
            'onboarded' => (bool) $store?->onboarding_completed_at,
            'adminUrl' => $store?->adminUrl(),
            'planName' => $store ? config('shopify.billing.plans.'.$store->effectivePlan().'.name') : null,
            'menu' => $access ? self::menu($request, $store) : [],
            'nav' => $access ? self::nav($request, $store) : null,
            'limit' => $access && ! $request->routeIs('app.settings.billing') ? self::limit($store) : null,
            'legal' => $user ? \App\Support\Legal::noticeFor($store) : null,
        ];
    }

    /** The app's main sections, shown in the top bar (Shopify's app menu lists the same). */
    private static function menu(Request $request, Store $store): array
    {
        $items = [['Home', 'app.dashboard', ['app.dashboard']]];
        if (! $store->onboarding_completed_at) {
            $items[] = ['Get started', 'app.onboarding', ['app.onboarding*']];
        }
        array_push($items,
            ['Products & costs', 'app.products.index', ['app.products.*']],
            ['Settings', 'app.settings.general', ['app.settings.*']],
            ['Support', 'app.support.index', ['app.support.*']],
        );

        return array_map(fn ($i) => ['label' => $i[0], 'href' => self::path($i[1]), 'active' => $request->routeIs(...$i[2])], $items);
    }

    private static function nav(Request $request, Store $store): ?array
    {
        if (! $request->routeIs('app.settings.*')) {
            return null;
        }
        $user = $request->attributes->get('storeUser');
        $is = fn (...$names) => fn () => $request->routeIs(...$names);
        $items = [
            ['General', 'settings', 'app.settings.general', $is('app.settings.general')],
            ['Costs & fees', 'tag', 'app.settings.costs', $is('app.settings.costs')],
            ['Store & data sync', 'store', 'app.settings.store', $is('app.settings.store')],
            ['Integrations', 'plug', 'app.settings.integrations', $is('app.settings.integrations')],
            ['Users & roles', 'users', 'app.settings.users', $is('app.settings.users'), 'manage_users'],
            ['Billing', 'card', 'app.settings.billing', $is('app.settings.billing')],
            ['Privacy', 'lock', 'app.settings.privacy', $is('app.settings.privacy')],
            ['Activity', 'list', 'app.settings.activity', $is('app.settings.activity'), 'view_activity'],
        ];
        $items = array_values(array_filter($items, fn ($i) => ! isset($i[4]) || $user?->can($i[4])));

        return ['section' => 'settings', 'label' => 'Settings', 'groups' => [['heading' => null, 'items' => array_map(fn ($i) => [
            'label' => $i[0], 'icon' => $i[1], 'tone' => 'default', 'href' => self::path($i[2]), 'active' => (bool) $i[3](),
        ], $items)]]];
    }

    /**
     * The usage warning: the meter closest to (or at) its limit, once it passes the warning share.
     */
    private static function limit(Store $store): ?array
    {
        $warnAt = (float) config('shopify.billing.warn_at', 0.8);
        $meter = collect(app(\App\Services\Usage::class)->summary($store))
            ->filter(fn ($m) => $m['limit'] !== null && $m['limit'] > 0 && $m['used'] / $m['limit'] >= $warnAt)
            ->sortByDesc(fn ($m) => $m['used'] / $m['limit'])->first();
        if ($meter === null) {
            return null;
        }
        $full = $meter['used'] >= $meter['limit'];
        $plan = config('shopify.billing.plans.'.$store->effectivePlan().'.name');
        $label = mb_strtolower($meter['label']);

        return [
            // At the limit is normal (nothing breaks), so it's a notice, not an error.
            'state' => 'near',
            'meter' => $meter['meter'],
            'title' => $full ? "You've reached your plan's limit for {$label}." : "You're close to your plan's limit for {$label}.",
            'text' => "{$plan} includes {$meter['limit']}; you're using {$meter['used']}. ".($full ? 'Everything live keeps working; upgrade to add more.' : 'Upgrade any time for more.'),
            'action' => 'See plans',
        ];
    }

    /**
     * Named app routes for the client's route() helper: name => URI with {params}.
     *
     * @return array<string, string>
     */
    public static function routes(): array
    {
        return collect(app('router')->getRoutes()->getRoutesByName())
            ->filter(fn ($route, $name) => str_starts_with($name, 'app.'))
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->all();
    }

    /** A route as an app-relative path (the client adds the shop). */
    public static function path(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }
}
