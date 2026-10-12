<?php

namespace Tests\Feature;

use App\Models\Mail\EmailDelivery;
use App\Models\Mail\EmailProvider;
use App\Models\User;
use App\Services\Mail\Drivers;
use App\Services\Mail\EmailSender;
use App\Services\Mail\OutgoingEmail;
use App\Support\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class EmailProvidersTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        EmailSettings::save(['enabled' => true, 'strategy' => 'priority', 'from_email' => 'hello@orderorbit.space', 'from_name' => 'Growvia', 'reply_to' => '']);
    }

    private function provider(string $driver, array $attributes = []): EmailProvider
    {
        $credentials = ['brevo' => ['api_key' => 'xkeysib-1'], 'resend' => ['api_key' => 're_1'], 'mailjet' => ['api_key' => 'mj', 'secret_key' => 'mjs'], 'postmark' => ['server_token' => 'pm', 'stream' => 'outbound'], 'sendgrid' => ['api_key' => 'SG.1']][$driver];

        return EmailProvider::create($attributes + ['name' => ucfirst($driver), 'driver' => $driver, 'credentials' => $credentials, 'priority' => 10, 'is_active' => true]);
    }

    private function email(string $to = 'shopper@example.com'): OutgoingEmail
    {
        return OutgoingEmail::fromText($to, 'Hello', "Line one\n\nLine <two>");
    }

    public function test_fails_over_when_a_provider_reaches_its_limit_and_rests_until_tomorrow(): void
    {
        $brevo = $this->provider('brevo', ['priority' => 10]);
        $resend = $this->provider('resend', ['priority' => 20]);
        Http::fake([
            'api.brevo.com/*' => Http::response(['code' => 'not_enough_credits', 'message' => 'You have exceeded your daily sending limit'], 402),
            'api.resend.com/*' => Http::response(['id' => 'rs_1']),
        ]);

        $result = app(EmailSender::class)->send($this->email());
        $this->assertTrue($result->ok());
        $this->assertSame([$resend->id, 'rs_1'], [$result->provider->id, $result->messageId]);
        $brevo->refresh();
        $this->assertSame('limited', $brevo->state());
        $this->assertTrue($brevo->paused_until->equalTo(now()->utc()->startOfDay()->addDay()));
        $this->assertSame(['failed', 'sent'], EmailDelivery::orderBy('id')->pluck('status')->all());

        // The resting provider isn't tried again today…
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'rs_2'])]);
        app(EmailSender::class)->send($this->email());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'brevo'));
        // …and is back tomorrow.
        $this->travel(1)->days();
        $this->assertSame($brevo->id, app(EmailSender::class)->candidates()->first()->id);

        // The request carried the sender, recipient and the escaped body.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resend') && $r['from'] === '"Growvia" <hello@orderorbit.space>' && $r['to'] === ['shopper@example.com'] && str_contains($r['html'], 'Line &lt;two&gt;'));
    }

    public function test_daily_limits_move_sending_on_before_the_provider_refuses_and_balance_spreads_it(): void
    {
        $resend = $this->provider('resend', ['priority' => 10, 'daily_limit' => 2]);
        $mailjet = $this->provider('mailjet', ['priority' => 20, 'daily_limit' => 200]);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'r']), 'api.mailjet.com/*' => Http::response(['Messages' => [['To' => [['MessageID' => 77]]]]])]);

        $sender = app(EmailSender::class);
        $used = collect(range(1, 4))->map(fn () => $sender->send($this->email())->provider->driver)->all();
        $this->assertSame(['resend', 'resend', 'mailjet', 'mailjet'], $used);
        $this->assertSame([2, 2], [$resend->fresh()->sent_today, $resend->fresh()->sent_month]);
        $this->assertSame('limited', $resend->fresh()->state());

        // New day: counters reset; "spread" picks whichever has the most allowance left.
        $this->travel(1)->days();
        EmailSettings::save(['strategy' => 'balance'] + EmailSettings::get());
        $mailjet->forceFill(['sent_today' => 0])->save();
        $this->assertSame('resend', $sender->candidates('balance')->first()->driver);
        $sender->send($this->email()); // resend now at 1/2 = 50% left, mailjet 100%
        $this->assertSame('mailjet', $sender->candidates('balance')->first()->driver);
    }

    public function test_bad_keys_rest_the_provider_bad_recipients_stop_and_nothing_available_waits(): void
    {
        $sendgrid = $this->provider('sendgrid', ['priority' => 10]);
        $postmark = $this->provider('postmark', ['priority' => 20]);
        Http::fake([
            'api.sendgrid.com/*' => Http::response(['errors' => [['message' => 'The provided authorization grant is invalid']]], 401),
            'api.postmarkapp.com/*' => Http::response(['ErrorCode' => 406, 'Message' => 'You tried to send to recipient(s) that have been marked as inactive.'], 422),
        ]);

        $result = app(EmailSender::class)->send($this->email());
        $this->assertSame('failed', $result->status, 'An inactive recipient fails for good: other providers would refuse it too.');
        $this->assertSame('failing', $sendgrid->fresh()->state());
        $this->assertTrue($sendgrid->fresh()->paused_until->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertSame('ok', $postmark->fresh()->state());

        $postmark->forceFill(['is_active' => false])->save();
        $this->assertSame('waiting', app(EmailSender::class)->send($this->email())->status);
        $this->assertSame('failed', app(EmailSender::class)->send($this->email('not-an-email'))->status);

        EmailSettings::save(['enabled' => false] + EmailSettings::get());
        $this->assertStringContainsString('switched off', app(EmailSender::class)->send($this->email())->error);

        $this->assertSame('The provided authorization grant is invalid', $sendgrid->fresh()->last_error === null ? null : \Illuminate\Support\Str::after($sendgrid->fresh()->last_error, '[auth] '));

        // How refusals are read.
        $this->assertSame('rate', Drivers::classify(429, '{"name":"rate_limit_exceeded"}', '5')->kind);
        $this->assertSame('quota', Drivers::classify(429, '{"name":"daily_quota_exceeded"}')->kind);
        $this->assertSame('quota', Drivers::classify(401, 'Maximum credits exceeded')->kind);
        $this->assertSame('config', Drivers::classify(400, 'Sender is not verified')->kind);
        $this->assertSame('temporary', Drivers::classify(503, 'Service Unavailable')->kind);
        $this->assertSame('auth', Drivers::classify(535, '535 5.7.8 Username and Password not accepted', null, true)->kind);
        $this->assertSame('rate', Drivers::classify(421, '421 Too many connections', null, true)->kind);
        $this->assertSame('rejected', Drivers::classify(550, '550 5.1.1 The email account that you tried to reach does not exist (user unknown)', null, true)->kind);
    }

    public function test_laravel_mail_goes_through_the_providers(): void
    {
        $this->provider('resend');
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'rs_9'])]);
        Mail::mailer('platform')->raw("Welcome aboard.\n\nSee you soon.", fn ($m) => $m->to('sam@example.com')->subject('Welcome'));
        Http::assertSent(fn (Request $r) => $r['to'] === ['sam@example.com'] && $r['subject'] === 'Welcome' && str_contains($r['html'], 'Welcome aboard.'));
    }

    public function test_super_admin_manages_providers_and_keys_stay_secret(): void
    {
        $admin = User::forceCreate(['name' => 'Ava', 'email' => 'ava@orderorbit.space', 'password' => 'secret-password-123', 'is_admin' => true, 'admin_role' => 'super_admin']);
        $this->actingAs($admin)->get('/admin/email')->assertOk()->assertSee('No providers yet');
        $this->actingAs($admin)->get('/admin/email/providers/new')->assertOk()->assertSee('SMTP2GO')->assertSee('Mailjet');
        $this->actingAs($admin)->get('/admin/email/providers/new?driver=brevo')->assertOk()->assertSee('300');

        // A shortened key (what the provider's key list shows) is caught before it's saved.
        $this->actingAs($admin)->post('/admin/email/providers', ['driver' => 'resend', 'name' => 'Resend', 'credentials' => ['api_key' => 're_AbCd1234'], 'is_active' => '1'])
            ->assertSessionHasErrors(['credentials.api_key' => 'That API key doesn\'t look right (11 characters). A Resend key starts with re_ and is about 36 characters. The API Keys list only shows the first few: copy the full key when you create it (it\'s shown once).']);
        $this->assertSame(0, EmailProvider::count());

        $key = 'xkeysib-'.str_repeat('a1', 32).'-AbCdEf1234561234';
        $this->actingAs($admin)->post('/admin/email/providers', ['driver' => 'brevo', 'name' => 'Brevo main', 'credentials' => ['api_key' => $key], 'daily_limit' => 300, 'is_active' => '1'])->assertRedirect('/admin/email');
        $brevo = EmailProvider::sole();
        $this->assertSame($key, $brevo->credentials['api_key']);
        $this->assertStringNotContainsString('xkeysib', DB::table('email_providers')->value('credentials'), 'Stored encrypted.');
        $this->actingAs($admin)->get("/admin/email/providers/{$brevo->id}")->assertOk()->assertDontSee($key)->assertSee('ends in 1234');

        // Saving without a key keeps it; saving also clears a pause.
        $brevo->forceFill(['status' => 'failing', 'paused_until' => now()->addHour()])->save();
        $this->actingAs($admin)->post("/admin/email/providers/{$brevo->id}", ['name' => 'Brevo', 'credentials' => ['api_key' => ''], 'daily_limit' => 250, 'is_active' => '1'])->assertRedirect('/admin/email');
        $brevo->refresh();
        $this->assertSame([$key, 250, 'ok', null], [$brevo->credentials['api_key'], $brevo->daily_limit, $brevo->state(), $brevo->paused_until]);

        // Test email through that provider only.
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        $this->actingAs($admin)->post("/admin/email/providers/{$brevo->id}/test", ['to' => 'ava@orderorbit.space'])->assertSessionHas('status');
        $this->actingAs($admin)->get('/admin/email')->assertSee('Sent today')->assertSee('Next email goes through Brevo');
        $this->actingAs($admin)->get('/admin/email/log')->assertOk()->assertSee('Test email from Store Intelligence');

        // Order, settings, switch off, remove.
        $this->actingAs($admin)->post('/admin/email/providers', ['driver' => 'smtp', 'name' => 'Zoho', 'credentials' => ['host' => 'smtp.zoho.com', 'port' => '587', 'username' => 'u', 'password' => 'p', 'encryption' => 'tls'], 'is_active' => '1'])->assertRedirect();
        $zoho = EmailProvider::where('name', 'Zoho')->sole();
        $this->actingAs($admin)->post("/admin/email/providers/{$zoho->id}/up")->assertRedirect();
        $this->assertSame(['Zoho', 'Brevo'], EmailProvider::orderBy('priority')->pluck('name')->all());
        $this->actingAs($admin)->post('/admin/email/settings', ['enabled' => '1', 'strategy' => 'balance', 'from_email' => 'hi@orderorbit.space', 'from_name' => 'OO'])->assertRedirect();
        $this->assertSame(['balance', 'hi@orderorbit.space', true], [EmailSettings::get()['strategy'], EmailSettings::get()['from_email'], EmailSettings::get()['enabled']]);
        $this->actingAs($admin)->post("/admin/email/providers/{$zoho->id}/toggle")->assertRedirect();
        $this->assertFalse($zoho->fresh()->is_active);
        $this->actingAs($admin)->post("/admin/email/providers/{$zoho->id}/delete")->assertRedirect();
        $this->assertSame(1, EmailProvider::count());

        $ops = User::forceCreate(['name' => 'Op', 'email' => 'op@orderorbit.space', 'password' => 'secret-password-123', 'is_admin' => true, 'admin_role' => 'operations']);
        $this->actingAs($ops)->get('/admin/email')->assertForbidden();
    }
}
