<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * A provider refused or failed a send. kind decides what happens next:
 * quota (limit reached: rest until the next day), rate (slow down briefly), auth / config
 * (key or sender setup is wrong: rest until fixed), rejected (this recipient can't get email:
 * don't try other providers), temporary (network or provider outage: try the next one).
 */
class ProviderError extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
