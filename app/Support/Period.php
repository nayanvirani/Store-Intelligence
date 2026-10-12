<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The date range a report shows (Final MVP F12: presets and a custom range). Free plans see the last
 * 30 days; other plans pick any range within their plan's history. The previous period has the same
 * length, directly before.
 */
class Period
{
    public const PRESETS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'mtd' => 'Month to date', 'last_month' => 'Last month', 'custom' => 'Custom'];

    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $key,
        public bool $locked,
    ) {}

    public static function from(Request $request, Store $store): self
    {
        $tz = $store->timezone ?: 'UTC';
        $full = $store->planIncludes('profit_dashboard');
        $key = $full && array_key_exists((string) $request->query('range'), self::PRESETS) ? (string) $request->query('range') : (string) ($full ? ($store->setting('default_range') ?? '30') : '30');
        $key = array_key_exists($key, self::PRESETS) ? $key : '30';
        $today = now($tz)->startOfDay();

        [$from, $to] = match ($key) {
            'mtd' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => self::custom($request, $tz, $today),
            default => [$today->copy()->subDays((int) $key - 1), $today->copy()],
        };
        if (($start = $store->historyStart()) && $from->lt($start)) {
            $from = $start->copy()->setTimezone($tz)->startOfDay();
        }
        if ($from->gt($to)) {
            $from = $to->copy();
        }

        return new self($from, $to, $key, ! $full);
    }

    private static function custom(Request $request, string $tz, Carbon $today): array
    {
        try {
            $from = Carbon::parse((string) $request->query('from'), $tz)->startOfDay();
            $to = Carbon::parse((string) $request->query('to'), $tz)->startOfDay();
        } catch (\Throwable) {
            return [$today->copy()->subDays(29), $today->copy()];
        }
        $to = $to->gt($today) ? $today->copy() : $to;

        return $from->gt($to) ? [$to, $from->gt($today) ? $today->copy() : $from] : [$from, $to];
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function previous(): array
    {
        return [$this->from->copy()->subDays($this->days()), $this->from->copy()->subDay()];
    }

    public function label(): string
    {
        return $this->key === 'custom' || $this->key === 'last_month'
            ? $this->from->format('M j').' – '.$this->to->format($this->from->year === $this->to->year ? 'M j, Y' : 'M j, Y')
            : self::PRESETS[$this->key];
    }

    public function toArray(Store $store): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'presets' => $this->locked ? ['30' => self::PRESETS['30']] : self::PRESETS,
            'locked' => $this->locked,
            'min' => $store->historyStart()?->setTimezone($store->timezone ?: 'UTC')->toDateString(),
        ];
    }
}
