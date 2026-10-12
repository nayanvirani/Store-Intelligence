<?php

namespace App\Services;

use App\Models\Store;
use App\Models\UsageRecord;
use Closure;

/**
 * Plan usage meters. Feature modules register how to count their items as they are built;
 * unregistered meters count from usage_records (monthly meters) or as zero.
 */
class Usage
{
    /** Every plan limit, set per plan (and per store) in the super admin. */
    public const METERS = [
        'history_days' => 'Days of history',
        'ad_accounts' => 'Connected ad accounts',
        'social_channels' => 'Connected social channels',
        'scheduled_posts' => 'Social posts per month',
        'monitored_urls' => 'Monitored URLs',
        'url_check_minutes' => 'URL check interval (minutes)',
        'ai_questions' => 'Ask your store questions per month',
        'team_members' => 'Team members',
    ];

    /** Group headings for the limits, in order. */
    public const GROUPS = [
        'Data' => ['history_days'],
        'Ads & social' => ['ad_accounts', 'social_channels', 'scheduled_posts'],
        'Protection' => ['monitored_urls', 'url_check_minutes'],
        'AI & team' => ['ai_questions', 'team_members'],
    ];

    /** The module (App\Support\Modules) each limit belongs to: with the module off, the limit is 0. */
    public const FEATURE = [
        'ad_accounts' => 'ads_dashboard',
        'social_channels' => 'social_publishing',
        'scheduled_posts' => 'social_publishing',
        'monitored_urls' => 'url_monitor',
        'url_check_minutes' => 'url_monitor',
        'ai_questions' => 'ask_ai',
    ];

    /** Settings, not counters: shown with the plan but never "used up". */
    public const SETTINGS = ['history_days', 'url_check_minutes'];

    /** Meters counted per calendar month (usage_records). */
    public const MONTHLY = ['scheduled_posts', 'ai_questions'];

    /** What each limit counts, where it isn't obvious from the name. */
    public const HELP = [
        'history_days' => 'How far back reports and charts can look. Leave empty for unlimited.',
        'scheduled_posts' => 'Posts scheduled or published this calendar month, across all channels.',
        'url_check_minutes' => 'How often each monitored URL is checked. Lower is more frequent.',
        'ai_questions' => 'Questions asked to "Ask your store" this calendar month.',
        'team_members' => 'Staff with access, including the owner.',
    ];

    /** @var array<string, Closure(Store): int> */
    private array $counters = [];

    public function register(string $meter, Closure $counter): void
    {
        $this->counters[$meter] = $counter;
    }

    public function current(Store $store, string $meter): int
    {
        if (isset($this->counters[$meter])) {
            return ($this->counters[$meter])($store);
        }

        if (in_array($meter, self::MONTHLY, true)) {
            return (int) UsageRecord::where('store_id', $store->id)
                ->where('meter', $meter)
                ->where('period_start', now()->startOfMonth()->toDateString())
                ->value('quantity');
        }

        return 0;
    }

    /**
     * Whether the store can add $adding more of $meter on its current plan.
     */
    public function allows(Store $store, string $meter, int $adding = 1): bool
    {
        if (! $store->hasPlanAccess()) {
            return false;
        }

        $limit = $store->planLimit($meter);

        return $limit === null || $this->current($store, $meter) + $adding <= $limit;
    }

    /** "The Starter plan includes 10 monitored URLs. …" for a limit that's been reached. */
    public static function limitMessage(Store $store, string $meter): string
    {
        $plan = config('shopify.billing.plans.'.$store->effectivePlan().'.name') ?: 'current';
        $limit = $store->hasPlanAccess() ? (int) $store->planLimit($meter) : 0;
        $what = mb_strtolower(self::METERS[$meter] ?? $meter);

        return $limit === 0
            ? ucfirst($what)." aren't included in the {$plan} plan. Upgrade to use them."
            : "The {$plan} plan includes {$limit} {$what}. Remove one or upgrade for more.";
    }

    public function increment(Store $store, string $meter, int $by = 1): void
    {
        $record = UsageRecord::firstOrCreate(
            ['store_id' => $store->id, 'meter' => $meter, 'period_start' => now()->startOfMonth()->toDateString()],
            ['quantity' => 0],
        );
        $record->increment('quantity', $by);
    }

    /**
     * Counters only (settings like history length are shown with the plan, not as usage).
     *
     * @return list<array{meter: string, label: string, used: int, limit: ?int, help: ?string}>
     */
    public function summary(Store $store): array
    {
        $meters = array_diff_key(self::METERS, array_flip(self::SETTINGS));

        return array_map(fn ($meter, $label) => [
            'meter' => $meter,
            'label' => $label,
            'used' => $this->current($store, $meter),
            'limit' => $store->hasPlanAccess() ? $store->planLimit($meter) : 0,
            'help' => self::HELP[$meter] ?? null,
        ], array_keys($meters), $meters);
    }
}
