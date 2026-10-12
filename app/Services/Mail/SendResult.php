<?php

namespace App\Services\Mail;

use App\Models\Mail\EmailProvider;

/** sent; waiting (try again later: no provider free right now); failed (won't succeed by retrying). */
class SendResult
{
    private function __construct(public readonly string $status, public readonly ?string $error = null, public readonly ?EmailProvider $provider = null, public readonly ?string $messageId = null) {}

    public static function sent(EmailProvider $provider, ?string $messageId): self
    {
        return new self('sent', null, $provider, $messageId);
    }

    public static function waiting(string $why): self
    {
        return new self('waiting', $why);
    }

    public static function failed(string $why, ?EmailProvider $provider = null): self
    {
        return new self('failed', $why, $provider);
    }

    public function ok(): bool
    {
        return $this->status === 'sent';
    }
}
