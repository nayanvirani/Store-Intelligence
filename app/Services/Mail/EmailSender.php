<?php

namespace App\Services\Mail;

use App\Models\Mail\EmailDelivery;
use App\Models\Mail\EmailProvider;
use App\Support\EmailSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends one email through the configured providers. Providers over their daily or monthly limit,
 * or resting after an error, are skipped; when a provider refuses (limit reached, bad key,
 * outage) the next one is tried at once. Providers that hit a limit rest until it resets.
 */
class EmailSender
{
    public function __construct(private readonly Drivers $drivers) {}

    public function send(OutgoingEmail $email, ?EmailProvider $only = null): SendResult
    {
        $settings = EmailSettings::get();
        if ($only === null && ! $settings['enabled']) {
            return SendResult::waiting('Email sending is switched off in the super admin.');
        }
        if (! filter_var($email->to, FILTER_VALIDATE_EMAIL)) {
            return SendResult::failed("“{$email->to}” isn't a valid email address.");
        }

        $providers = $only ? collect([$only]) : $this->candidates($settings['strategy']);
        if ($providers->isEmpty()) {
            return SendResult::waiting(EmailProvider::where('is_active', true)->exists()
                ? 'Every email provider has reached its limit or is resting after an error; sending resumes when one is available.'
                : 'No email provider is set up yet.');
        }

        $errors = [];
        foreach ($providers as $provider) {
            $fromEmail = $provider->from_email ?: $settings['from_email'];
            $fromName = $email->fromName ?: ($provider->from_name ?: $settings['from_name']);
            if (! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$provider->name}: no sender address set.";

                continue;
            }
            $email->replyTo ??= $settings['reply_to'] ?: null;

            try {
                $id = $this->drivers->send($provider, $email, $fromEmail, $fromName);
            } catch (ProviderError $e) {
                $this->penalize($provider, $e);
                $this->log($email, $provider, 'failed', $e->getMessage());
                $errors[] = "{$provider->name}: {$e->getMessage()}";
                if ($e->kind === 'rejected') {
                    return SendResult::failed($e->getMessage(), $provider);
                }

                continue;
            }

            $this->counted($provider);
            $this->log($email, $provider, 'sent', null, $id);

            return SendResult::sent($provider, $id);
        }

        return SendResult::waiting(Str::limit(implode(' · ', $errors), 900));
    }

    /** Active providers that can send now, in the order to try them. */
    public function candidates(string $strategy = 'priority'): Collection
    {
        $providers = EmailProvider::where('is_active', true)->orderBy('priority')->orderBy('id')->get()
            ->each->rollUsage()
            ->reject(fn (EmailProvider $p) => $p->overLimit() || $p->paused())
            ->values();

        return $strategy === 'balance'
            ? $providers->sortBy([fn ($a, $b) => $b->remainingShare() <=> $a->remainingShare(), fn ($a, $b) => $a->priority <=> $b->priority])->values()
            : $providers;
    }

    private function counted(EmailProvider $provider): void
    {
        $provider->rollUsage();
        $provider->forceFill([
            'sent_today' => $provider->sent_today + 1, 'sent_month' => $provider->sent_month + 1,
            'last_sent_at' => now(), 'status' => 'ok', 'consecutive_failures' => 0, 'paused_until' => null,
        ])->save();
    }

    /** How long a provider rests after an error, by kind. */
    private function penalize(EmailProvider $provider, ProviderError $e): void
    {
        $failures = $provider->consecutive_failures + 1;
        $pause = match ($e->kind) {
            // A limit we didn't know about: rest until the provider's day (or month) resets.
            'quota' => ($provider->monthly_limit !== null && $provider->sent_month >= $provider->monthly_limit) ? now()->utc()->startOfMonth()->addMonth() : now()->utc()->startOfDay()->addDay(),
            'rate' => now()->addSeconds(min(3600, $e->retryAfter ?? 60)),
            'auth', 'config' => now()->addMinutes(30),
            'temporary' => $failures >= 3 ? now()->addMinutes(5) : null,
            default => null, // rejected: the recipient's problem, not the provider's
        };
        $provider->forceFill([
            'status' => match ($e->kind) { 'quota', 'rate' => 'limited', 'auth', 'config' => 'failing', default => $provider->status },
            'paused_until' => $pause ?? $provider->paused_until,
            'consecutive_failures' => $e->kind === 'rejected' ? $provider->consecutive_failures : $failures,
            'last_error' => Str::limit("[{$e->kind}] ".$e->getMessage(), 1000), 'last_error_at' => now(),
        ])->save();
    }

    private function log(OutgoingEmail $email, EmailProvider $provider, string $status, ?string $error = null, ?string $messageId = null): void
    {
        EmailDelivery::create([
            'store_id' => $email->storeId, 'email_provider_id' => $provider->id, 'provider_name' => $provider->name,
            'category' => $email->category, 'to_email' => Str::limit($email->to, 190, ''), 'subject' => Str::limit($email->subject, 195),
            'status' => $status, 'error' => $error ? Str::limit($error, 1000) : null, 'message_id' => $messageId ? Str::limit($messageId, 195, '') : null,
        ]);
    }
}
