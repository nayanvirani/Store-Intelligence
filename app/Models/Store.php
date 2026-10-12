<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Store extends Model
{
    protected $fillable = [
        'shop_domain', 'access_token', 'refresh_token', 'access_token_expires_at', 'scopes',
        'name', 'email', 'currency', 'timezone', 'country', 'shopify_plan', 'capabilities', 'capabilities_checked_at', 'plan', 'plan_expires_at',
        'onboarding_completed_at', 'installed_at', 'uninstalled_at',
    ];

    /** Never serialized (the React admin receives page data as JSON). */
    protected $hidden = ['access_token', 'refresh_token', 'access_token_expires_at', 'scopes'];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array', 'legal_acks' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'capabilities' => 'array', 'privacy' => 'array', 'settings' => 'array',
            'capabilities_checked_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function costRules(): HasMany
    {
        return $this->hasMany(CostRule::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'ACTIVE')->latestOfMany();
    }

    public function isInstalled(): bool
    {
        return $this->access_token !== null && $this->uninstalled_at === null;
    }

    public function needsTokenRefresh(): bool
    {
        return $this->access_token_expires_at !== null && $this->access_token_expires_at->subMinutes(5)->isPast();
    }

    public function handle(): string
    {
        return str_replace('.myshopify.com', '', $this->shop_domain);
    }

    /**
     * The plan whose features and limits apply right now: the subscribed plan
     * (including a cancelled plan's paid-up grace period), or the configured
     * plan for our own test shops. Null means no access.
     */
    public function effectivePlan(): ?string
    {
        // A complimentary plan from the Internal Admin wins while it lasts.
        if ($comp = $this->compPlan()) {
            return $comp;
        }
        if ($this->plan !== null && ($this->plan_expires_at === null || $this->plan_expires_at->isFuture())) {
            return $this->plan;
        }

        return $this->isTestShop() ? config('shopify.test_shop_plan') : null;
    }

    /** The complimentary plan granted in the Internal Admin, while it lasts. */
    public function compPlan(): ?string
    {
        $e = $this->entitlements ?? [];
        $plan = $e['plan'] ?? null;
        if (! $plan || ! array_key_exists($plan, (array) config('shopify.billing.plans'))) {
            return null;
        }

        return empty($e['plan_until']) || \Illuminate\Support\Carbon::parse($e['plan_until'])->endOfDay()->isFuture() ? $plan : null;
    }

    /** Every module this store can use: its plan's, plus or minus the store's own overrides. */
    public function modules(): array
    {
        $e = $this->entitlements ?? [];
        $plan = (array) config('shopify.billing.plans.'.$this->effectivePlan().'.includes', []);

        return array_values(array_diff(array_unique(array_merge($plan, $e['modules_on'] ?? [])), $e['modules_off'] ?? []));
    }

    public function hasPlanAccess(): bool
    {
        return $this->effectivePlan() !== null;
    }

    public function isTestShop(): bool
    {
        return in_array($this->shop_domain, config('shopify.test_shops', []), true);
    }

    /**
     * Shopify's hosted plan picker (Managed Pricing).
     */
    public function pricingUrl(): string
    {
        return $this->adminUrl('charges/'.(Cache::get('shopify.app_handle') ?: config('shopify.app_handle')).'/pricing_plans');
    }

    /**
     * Whether the store can use a feature (App\Support\Modules): its plan's features, plus or
     * minus the overrides set for this store in the super admin. A feature with a parent (e.g.
     * Quantity breaks under Bundles) also needs the parent.
     */
    public function planIncludes(string $feature): bool
    {
        $modules = $this->modules();

        return collect(\App\Support\Modules::chain($feature))->every(fn ($key) => in_array($key, $modules, true));
    }

    /** Fingerprint of what the store may use; when it changes, plan limits are re-applied. */
    public function entitlementsFingerprint(): string
    {
        $modules = $this->modules();
        sort($modules);

        return hash('sha256', json_encode([$this->effectivePlan(), $modules, collect(array_keys(\App\Services\Usage::METERS))->mapWithKeys(fn ($m) => [$m => $this->planLimit($m)])->all()]));
    }

    /**
     * Plan limit for a usage meter; null means unlimited, 0 when there is no plan.
     */
    public function planLimit(string $meter): ?int
    {
        // A module that's off (for the plan or this store) has nothing to count: its limit is 0.
        if (($feature = \App\Services\Usage::FEATURE[$meter] ?? null) && ! $this->planIncludes($feature)) {
            return 0;
        }
        // A store-level override (null = unlimited) wins over the plan's limit.
        if (array_key_exists($meter, $this->entitlements['limits'] ?? [])) {
            return $this->entitlements['limits'][$meter] === null ? null : (int) $this->entitlements['limits'][$meter];
        }
        $limits = config('shopify.billing.plans.'.$this->effectivePlan().'.limits');

        return is_array($limits) && array_key_exists($meter, $limits) ? $limits[$meter] : 0;
    }

    /**
     * Required scopes that Shopify has not granted. write_x implies read_x.
     *
     * @return list<string>
     */
    public function missingScopes(): array
    {
        $granted = array_filter(explode(',', (string) $this->scopes));
        $required = array_filter(explode(',', (string) config('shopify.scopes')));

        return array_values(array_filter($required, fn ($scope) => ! in_array($scope, $granted, true)
            && ! in_array(str_replace('read_', 'write_', $scope), $granted, true)));
    }

    public function hasScope(string $scope): bool
    {
        $granted = array_filter(explode(',', (string) $this->scopes));

        return in_array($scope, $granted, true) || in_array(str_replace('read_', 'write_', $scope), $granted, true);
    }

    /** Settings → Privacy, with defaults. */
    public const PRIVACY_DEFAULTS = ['ai_enabled' => true];

    /** Settings, with defaults (Final MVP F3). */
    public const SETTINGS_DEFAULTS = [
        'reporting_currency' => null, // null: the shop currency
        'default_range' => 'last_30_days',
        'week_starts_on' => 'monday',
        'exclude_test_orders' => true,
        'default_margin' => null, // percent assumed for products without a cost (labelled); null = off
        'digest' => 'daily', // daily | weekly | off
        'digest_hour' => 8,
        'alert_emails' => [],
        'quiet_hours' => null, // e.g. ['22:00', '07:00']
    ];

    public function setting(string $key): mixed
    {
        return array_key_exists($key, $this->settings ?? []) ? $this->settings[$key] : (self::SETTINGS_DEFAULTS[$key] ?? null);
    }

    /** All settings with defaults filled in. */
    public function allSettings(): array
    {
        return array_merge(self::SETTINGS_DEFAULTS, array_intersect_key($this->settings ?? [], self::SETTINGS_DEFAULTS));
    }

    public function reportingCurrency(): string
    {
        return $this->setting('reporting_currency') ?: ($this->currency ?: 'USD');
    }

    /** The first day reports may show, from the plan's history limit. */
    public function historyStart(): ?\Illuminate\Support\Carbon
    {
        $days = $this->planLimit('history_days');

        return $days === null ? null : now()->subDays(max(0, $days))->startOfDay();
    }

    public function privacy(string $key): mixed
    {
        return ($this->privacy ?? [])[$key] ?? self::PRIVACY_DEFAULTS[$key];
    }

    public function capability(string $key): mixed
    {
        return $this->capabilities[$key] ?? null;
    }

    public function adminUrl(string $path = ''): string
    {
        return 'https://admin.shopify.com/store/'.$this->handle().($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    public function activeOwners(): HasMany
    {
        return $this->users()->where('role', 'owner')->whereNull('disabled_at')->whereNotNull('shopify_user_id');
    }
}
