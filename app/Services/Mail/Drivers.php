<?php

namespace App\Services\Mail;

use App\Models\Mail\EmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The email services we can send through, and how to call each one's API.
 * Every send returns the provider's message id or throws a ProviderError saying what went wrong.
 */
class Drivers
{
    /**
     * driver => label, credential fields [key => [label, secret?, help]], default free-tier limits [daily, monthly], note.
     */
    public const ALL = [
        'brevo' => ['label' => 'Brevo', 'fields' => ['api_key' => ['API key', true, 'Brevo → SMTP & API → API keys.']], 'limits' => [300, null], 'note' => 'Free: 300 emails a day.', 'url' => 'https://app.brevo.com/settings/keys/api'],
        'resend' => ['label' => 'Resend', 'fields' => ['api_key' => ['API key', true, 'Resend → API Keys (starts with re_).']], 'limits' => [100, 3000], 'note' => 'Free: 100 a day, 3,000 a month.', 'url' => 'https://resend.com/api-keys'],
        'mailjet' => ['label' => 'Mailjet', 'fields' => ['api_key' => ['API key', true, null], 'secret_key' => ['Secret key', true, 'Mailjet → Account settings → API key management.']], 'limits' => [200, 6000], 'note' => 'Free: 200 a day, 6,000 a month.', 'url' => 'https://app.mailjet.com/account/apikeys'],
        'mailgun' => ['label' => 'Mailgun', 'fields' => ['api_key' => ['API key', true, 'Mailgun → API Security → sending or private key.'], 'domain' => ['Sending domain', false, 'e.g. mg.example.com'], 'region' => ['Region', false, 'us or eu']], 'limits' => [100, 3000], 'note' => 'Free: 100 a day.', 'url' => 'https://app.mailgun.com/settings/api_security'],
        'sendgrid' => ['label' => 'SendGrid', 'fields' => ['api_key' => ['API key', true, 'SendGrid → Settings → API Keys (Mail Send permission).']], 'limits' => [100, null], 'note' => 'Free: 100 a day.', 'url' => 'https://app.sendgrid.com/settings/api_keys'],
        'postmark' => ['label' => 'Postmark', 'fields' => ['server_token' => ['Server API token', true, 'Postmark → Server → API Tokens.'], 'stream' => ['Message stream', false, 'Usually "outbound".']], 'limits' => [null, 100], 'note' => 'Free: 100 a month.', 'url' => 'https://account.postmarkapp.com/servers'],
        'smtp2go' => ['label' => 'SMTP2GO', 'fields' => ['api_key' => ['API key', true, 'SMTP2GO → Sending → API Keys.']], 'limits' => [200, 1000], 'note' => 'Free: 1,000 a month, 200 a day.', 'url' => 'https://app.smtp2go.com/sending/apikeys/'],
        'smtp' => ['label' => 'SMTP (Gmail, Zoho, Amazon SES, any)', 'fields' => ['host' => ['Host', false, 'e.g. smtp.gmail.com, smtp.zoho.com, email-smtp.us-east-1.amazonaws.com'], 'port' => ['Port', false, '587 (TLS) or 465 (SSL)'], 'username' => ['Username', false, null], 'password' => ['Password / app password', true, 'For Gmail, create an app password.'], 'encryption' => ['Encryption', false, 'tls, ssl or none']], 'limits' => [null, null], 'note' => 'Set limits to your account\'s, e.g. Gmail 500 a day.', 'url' => null],
    ];

    /**
     * What each key looks like, so a shortened or wrong value is caught when it's saved rather
     * than when the first email fails. driver.field => [regex, hint]
     */
    public const KEY_FORMATS = [
        'resend.api_key' => ['/^re_[A-Za-z0-9_]{25,}$/', 'A Resend key starts with re_ and is about 36 characters. The API Keys list only shows the first few: copy the full key when you create it (it\'s shown once).'],
        'brevo.api_key' => ['/^xkeysib-[A-Za-z0-9-]{40,}$/', 'A Brevo API key starts with xkeysib- (not the SMTP key, which starts with xsmtpsib-).'],
        'sendgrid.api_key' => ['/^SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}$/', 'A SendGrid key starts with SG. and has two parts separated by a dot.'],
        'mailgun.api_key' => ['/^[A-Za-z0-9-]{30,}$/', 'Copy the full Mailgun API key.'],
        'mailjet.api_key' => ['/^[a-f0-9]{32}$/i', 'A Mailjet API key is 32 letters and numbers.'],
        'mailjet.secret_key' => ['/^[a-f0-9]{32}$/i', 'A Mailjet secret key is 32 letters and numbers.'],
        'postmark.server_token' => ['/^[a-f0-9-]{36}$/i', 'A Postmark server token looks like xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.'],
        'smtp2go.api_key' => ['/^api-[A-Za-z0-9]{20,}$/', 'An SMTP2GO key starts with api-.'],
    ];

    public static function defaults(string $driver): array
    {
        return ['mailgun' => ['region' => 'us'], 'postmark' => ['stream' => 'outbound'], 'smtp' => ['port' => '587', 'encryption' => 'tls']][$driver] ?? [];
    }

    /** @return string|null the provider's message id */
    public function send(EmailProvider $provider, OutgoingEmail $email, string $fromEmail, string $fromName): ?string
    {
        $c = $provider->credentials ?? [];
        $from = self::address($fromEmail, $fromName);

        try {
            return match ($provider->driver) {
                'brevo' => $this->ok($this->http()->withHeaders(['api-key' => $c['api_key'] ?? ''])->post('https://api.brevo.com/v3/smtp/email', array_filter([
                    'sender' => ['email' => $fromEmail, 'name' => $fromName],
                    'to' => [array_filter(['email' => $email->to, 'name' => $email->toName])],
                    'subject' => $email->subject, 'htmlContent' => $email->html, 'textContent' => $email->text,
                    'replyTo' => $email->replyTo ? ['email' => $email->replyTo] : null,
                ])), 'messageId'),
                'resend' => $this->ok($this->http()->withToken($c['api_key'] ?? '')->post('https://api.resend.com/emails', array_filter([
                    'from' => $from, 'to' => [$email->to], 'subject' => $email->subject, 'html' => $email->html, 'text' => $email->text, 'reply_to' => $email->replyTo,
                ])), 'id'),
                'mailjet' => $this->ok($this->http()->withBasicAuth($c['api_key'] ?? '', $c['secret_key'] ?? '')->post('https://api.mailjet.com/v3.1/send', ['Messages' => [array_filter([
                    'From' => ['Email' => $fromEmail, 'Name' => $fromName], 'To' => [array_filter(['Email' => $email->to, 'Name' => $email->toName])],
                    'Subject' => $email->subject, 'TextPart' => $email->text, 'HTMLPart' => $email->html,
                    'ReplyTo' => $email->replyTo ? ['Email' => $email->replyTo] : null,
                ])]]), 'Messages.0.To.0.MessageID'),
                'mailgun' => $this->ok($this->http()->asForm()->withBasicAuth('api', $c['api_key'] ?? '')->post(
                    'https://'.(($c['region'] ?? 'us') === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net').'/v3/'.($c['domain'] ?? '').'/messages',
                    array_filter(['from' => $from, 'to' => $email->to, 'subject' => $email->subject, 'html' => $email->html, 'text' => $email->text, 'h:Reply-To' => $email->replyTo])
                ), 'id'),
                'sendgrid' => $this->ok($this->http()->withToken($c['api_key'] ?? '')->post('https://api.sendgrid.com/v3/mail/send', array_filter([
                    'personalizations' => [['to' => [array_filter(['email' => $email->to, 'name' => $email->toName])]]],
                    'from' => ['email' => $fromEmail, 'name' => $fromName],
                    'reply_to' => $email->replyTo ? ['email' => $email->replyTo] : null,
                    'subject' => $email->subject,
                    'content' => array_values(array_filter([$email->text ? ['type' => 'text/plain', 'value' => $email->text] : null, ['type' => 'text/html', 'value' => $email->html]])),
                ])), null, 'X-Message-Id'),
                'postmark' => $this->ok($this->http()->withHeaders(['X-Postmark-Server-Token' => $c['server_token'] ?? ''])->post('https://api.postmarkapp.com/email', array_filter([
                    'From' => $from, 'To' => $email->to, 'Subject' => $email->subject, 'HtmlBody' => $email->html, 'TextBody' => $email->text,
                    'ReplyTo' => $email->replyTo, 'MessageStream' => ($c['stream'] ?? '') ?: 'outbound',
                ])), 'MessageID'),
                'smtp2go' => $this->smtp2go($c, $email, $from),
                'smtp' => $this->smtp($c, $email, $fromEmail, $fromName),
                default => throw new ProviderError('config', "Unknown email service \"{$provider->driver}\"."),
            };
        } catch (ConnectionException $e) {
            throw new ProviderError('temporary', 'Could not reach '.$provider->label().': '.Str::limit($e->getMessage(), 200));
        }
    }

    public static function address(string $email, string $name): string
    {
        return $name === '' ? $email : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $name).'" <'.$email.'>';
    }

    private function http()
    {
        return Http::acceptJson()->asJson()->timeout(20)->connectTimeout(8);
    }

    private function ok(Response $response, ?string $idPath, ?string $idHeader = null): ?string
    {
        if ($response->successful()) {
            return $idHeader ? ($response->header($idHeader) ?: null) : (data_get($response->json(), $idPath) ?: null);
        }

        throw self::classify($response->status(), $response->body(), $response->header('Retry-After'));
    }

    private function smtp2go(array $c, OutgoingEmail $email, string $from): ?string
    {
        $response = $this->http()->withHeaders(['X-Smtp2go-Api-Key' => $c['api_key'] ?? ''])->post('https://api.smtp2go.com/v3/email/send', array_filter([
            'sender' => $from, 'to' => [$email->to], 'subject' => $email->subject, 'html_body' => $email->html, 'text_body' => $email->text,
            'custom_headers' => $email->replyTo ? [['header' => 'Reply-To', 'value' => $email->replyTo]] : null,
        ]));
        // SMTP2GO answers 200 with "failed" counts when it refuses a message.
        if ($response->successful() && (int) $response->json('data.failed', 0) > 0) {
            throw self::classify(400, json_encode($response->json('data.failures')), null);
        }

        return $this->ok($response, 'data.email_id');
    }

    private function smtp(array $c, OutgoingEmail $email, string $fromEmail, string $fromName): ?string
    {
        $encryption = strtolower((string) ($c['encryption'] ?? 'tls'));
        $mailer = Mail::build([
            'transport' => 'smtp', 'host' => $c['host'] ?? '', 'port' => (int) ($c['port'] ?? 587),
            'username' => $c['username'] ?? null, 'password' => $c['password'] ?? null, 'timeout' => 20,
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
        ]);
        try {
            $sent = $mailer->html($email->html, function ($m) use ($email, $fromEmail, $fromName) {
                $m->to($email->to, $email->toName)->from($fromEmail, $fromName)->subject($email->subject);
                if ($email->replyTo) {
                    $m->replyTo($email->replyTo);
                }
                if ($email->text) {
                    $m->text($email->text);
                }
            });
        } catch (TransportExceptionInterface $e) {
            $message = $e->getMessage();
            preg_match('/\b([45]\d\d)\b/', $message, $code);

            throw self::classify((int) ($code[1] ?? 0) ?: 451, $message, null, smtp: true);
        } catch (Throwable $e) {
            throw new ProviderError('temporary', Str::limit($e->getMessage(), 300));
        }

        return $sent?->getMessageId();
    }

    /** Turns a provider's refusal into: quota, rate, auth, config, rejected or temporary. */
    public static function classify(int $status, string $body, ?string $retryAfter = null, bool $smtp = false): ProviderError
    {
        $text = strtolower($body);
        // Providers answer in JSON: keep their human message rather than the raw payload.
        $json = json_decode($body, true);
        $readable = is_array($json) ? (data_get($json, 'message') ?? data_get($json, 'Message') ?? data_get($json, 'error.message') ?? data_get($json, 'errors.0.message') ?? data_get($json, 'ErrorMessage') ?? (is_string($json['error'] ?? null) ? $json['error'] : null)) : null;
        $message = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(is_string($readable) ? $readable : $body))), 300) ?: "HTTP {$status}";
        $has = fn (array $words) => Str::contains($text, $words);
        $quotaWords = ['quota', 'limit exceeded', 'limit reached', 'daily limit', 'monthly limit', 'credits', 'not_enough_credits', 'sending limit', 'exceeded your', 'plan limit', 'too many emails'];
        $recipientWords = ['recipient', 'invalid email', 'inactive', 'suppress', 'unsubscribed', 'bounced', 'blocked address', 'mailbox', 'user unknown', 'does not exist', 'blacklist'];

        if ($smtp) {
            return match (true) {
                $has($quotaWords) => new ProviderError('quota', $message),
                in_array($status, [421, 450, 451, 452], true) => new ProviderError('rate', $message, 120),
                in_array($status, [530, 534, 535], true) || $has(['authentication', 'username and password', 'auth']) => new ProviderError('auth', $message),
                in_array($status, [550, 551, 552, 553], true) && $has($recipientWords) => new ProviderError('rejected', $message),
                $status >= 500 => new ProviderError('config', $message),
                default => new ProviderError('temporary', $message),
            };
        }

        return match (true) {
            $status === 402 || $has($quotaWords) => new ProviderError('quota', $message),
            $status === 429 => new ProviderError('rate', $message, is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 60),
            in_array($status, [401, 403], true) => new ProviderError('auth', $message),
            $status >= 500 || $status === 0 => new ProviderError('temporary', $message),
            $has($recipientWords) => new ProviderError('rejected', $message),
            default => new ProviderError('config', $message),
        };
    }
}
