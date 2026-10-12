<?php

namespace App\Models\Mail;

use App\Services\Mail\Drivers;
use Illuminate\Database\Eloquent\Model;

/** One email service account. Credentials are encrypted and never serialized. */
class EmailProvider extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'is_active' => 'boolean', 'usage_day' => 'date',
            'paused_until' => 'datetime', 'last_error_at' => 'datetime', 'last_sent_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return Drivers::ALL[$this->driver]['label'] ?? $this->driver;
    }

    /** Resets the daily and monthly counters when a new day or month starts (UTC). */
    public function rollUsage(): void
    {
        $today = now()->utc()->toDateString();
        $month = now()->utc()->format('Y-m');
        $changes = [];
        if ($this->usage_day?->toDateString() !== $today) {
            $changes += ['usage_day' => $today, 'sent_today' => 0];
        }
        if ($this->usage_month !== $month) {
            $changes += ['usage_month' => $month, 'sent_month' => 0];
        }
        if ($changes) {
            $this->forceFill($changes)->save();
        }
    }

    public function overLimit(): bool
    {
        return ($this->daily_limit !== null && $this->sent_today >= $this->daily_limit)
            || ($this->monthly_limit !== null && $this->sent_month >= $this->monthly_limit);
    }

    public function paused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /** Share of today's allowance still free (1 when there's no daily limit). */
    public function remainingShare(): float
    {
        $day = $this->daily_limit ? max(0, $this->daily_limit - $this->sent_today) / $this->daily_limit : 1;
        $month = $this->monthly_limit ? max(0, $this->monthly_limit - $this->sent_month) / $this->monthly_limit : 1;

        return min($day, $month);
    }

    /** ok | paused | limited | failing | off, for the admin. */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'off',
            $this->overLimit() => 'limited',
            $this->paused() => $this->status === 'failing' ? 'failing' : 'limited',
            $this->status === 'failing' => 'failing',
            default => 'ok',
        };
    }
}
