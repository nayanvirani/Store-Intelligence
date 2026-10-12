<?php

namespace App\Services\Mail;

/** One email to send. Without a from address, the platform's default sender is used. */
class OutgoingEmail
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public ?string $text = null,
        public ?string $toName = null,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public string $category = 'system',
        public ?int $storeId = null,
    ) {}

    /** Plain text (e.g. a workflow's email body) as simple, safe HTML. */
    public static function fromText(string $to, string $subject, string $text, array $extra = []): self
    {
        $paragraphs = array_map(fn ($p) => '<p style="margin:0 0 14px">'.nl2br(e(trim($p))).'</p>', preg_split('/\n\s*\n/', trim($text)) ?: []);
        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#ffffff;color:#1a1a1a;font:15px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">'
            .'<div style="max-width:560px;margin:0 auto">'.implode('', $paragraphs).'</div></body></html>';

        return new self($to, $subject, $html, $text, ...$extra);
    }
}
