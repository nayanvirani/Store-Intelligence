<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\Store;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SupportAndAdminTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        Http::fake(fn (Request $r) => Http::response(['data' => []]));
        $this->store = $this->installedStore(['plan' => 'growth']);
    }

    private function admin(): User
    {
        return User::forceCreate(['name' => 'Ava Team', 'email' => 'ava@orderorbit.space', 'password' => 'secret-password-123', 'is_admin' => true]);
    }

    public function test_merchants_open_tickets_and_follow_the_conversation(): void
    {
        $owner = $this->member($this->store, 'owner');
        $this->page('/app/support', $owner)->assertOk()->assertJsonPath('component', 'support/index')->assertJsonPath('props.tickets', []);
        $this->postJson('/app/support', ['subject' => '', 'body' => 'x', 'category' => 'setup'], $this->as($owner))->assertStatus(422)->assertJsonValidationErrors('subject');

        $this->post('/app/support', [
            'subject' => 'Bundle not showing', 'body' => 'The bundle doesn\'t appear on the Serum page.', 'category' => 'profit', 'priority' => 'high',
            'attachments' => [UploadedFile::fake()->image('screen.png', 400, 300)],
        ], $this->as($owner))->assertRedirectContains('ticket_created');
        $ticket = Ticket::sole();
        $this->assertSame(['open', 'high', 'profit', $this->store->shop_domain], [$ticket->status, $ticket->priority, $ticket->category, $ticket->diagnostics['shop']]);
        $this->assertSame(1, $ticket->messages()->first()->attachments()->count());

        // The team replies, adds an internal note, and the merchant only sees the reply.
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/tickets/'.$ticket->id.'/reply', ['body' => 'Is the app embed on?'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/tickets/'.$ticket->id.'/reply', ['body' => 'Their theme is old.', 'internal' => '1'])->assertRedirect();
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->page('/app/support/'.$ticket->id, $owner)->assertOk()->assertSee('Is the app embed on?')->assertDontSee('Their theme is old.')->assertSee('screen.png');

        $attachment = $ticket->messages()->first()->attachments()->first();
        $this->get('/app/support/attachments/'.$attachment->id, $this->as($owner))->assertOk()->assertHeader('content-type', 'image/png');

        $this->post('/app/support/'.$ticket->id.'/reply', ['body' => 'Yes, it is on.'], $this->as($owner))->assertRedirectContains('reply_sent');
        $this->assertSame(['open', 'merchant'], [$ticket->fresh()->status, $ticket->fresh()->last_reply_by]);

        // Another store can't see it.
        $other = $this->installedStore(['shop_domain' => 'other.myshopify.com']);
        $this->assertNull(Ticket::where('store_id', $other->id)->first());
    }

    public function test_admin_requires_a_team_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('For the Store Intelligence team only.');
        User::forceCreate(['name' => 'Merchant', 'email' => 'm@example.com', 'password' => 'password-123', 'is_admin' => false]);
        $this->post('/admin/login', ['email' => 'm@example.com', 'password' => 'password-123'])->assertSessionHasErrors('email');
        $this->admin();
        $this->post('/admin/login', ['email' => 'ava@orderorbit.space', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => 'ava@orderorbit.space', 'password' => 'secret-password-123'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Installed stores')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->artisan('si:admin-user', ['email' => 'new@orderorbit.space'])->expectsOutputToContain('Password (shown once)')->assertSuccessful();
        $this->assertTrue(User::where('email', 'new@orderorbit.space')->value('is_admin'));
    }

    public function test_admin_pages_and_flags(): void
    {
        $admin = $this->admin();
        foreach (['/admin', '/admin/stores', '/admin/stores/'.$this->store->id, '/admin/failures', '/admin/sync', '/admin/flags', '/admin/tickets', '/admin/messages', '/admin/audit'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get('/admin/stores?q=demo')->assertSee($this->store->shop_domain);

        // Feature flags: everyone, or only listed stores (e.g. an integration in beta).
        $this->actingAs($admin)->post('/admin/flags', ['key' => 'integration.google_ads', 'description' => 'Google Ads beta', 'store_domains' => $this->store->shop_domain])->assertRedirect();
        $this->assertTrue(Features::enabled('integration.google_ads', $this->store));
        $this->assertFalse(Features::enabled('integration.google_ads', $this->installedStore(['shop_domain' => 'b.myshopify.com'])));
        $this->assertFalse(Features::enabled('missing_flag', $this->store));
        $owner = $this->member($this->store, 'owner');
        $this->page('/app/settings/integrations', $owner)->assertJsonPath('props.providers.0.key', 'google_ads')->assertJsonPath('props.providers.0.available', true);
        $this->actingAs($admin)->post('/admin/flags/'.FeatureFlag::sole()->id.'/delete')->assertRedirect();
        $this->assertFalse(Features::enabled('integration.google_ads', $this->store));
    }

    public function test_website_messages_reach_the_admin(): void
    {
        $this->post('/contact', ['name' => 'Lee', 'email' => 'lee@example.com', 'topic' => 'Sales', 'message' => 'Do you support multi-currency?'])->assertRedirect()->assertSessionHas('contact_sent');
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'topic' => 'Sales', 'message' => 'spam', 'website' => 'x'])->assertSessionHas('contact_sent');
        $this->assertSame(1, \App\Models\ContactSubmission::count(), 'The honeypot drops bots.');
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin')->assertSee('Website messages');
        $this->actingAs($admin)->get('/admin/messages')->assertOk()->assertSee('Do you support multi-currency?');
        $message = \App\Models\ContactSubmission::sole();
        $this->actingAs($admin)->post('/admin/messages/'.$message->id, ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $message->fresh()->status);
    }

    public function test_admins_change_their_password(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/account')->assertOk()->assertSee('Change password');
        $this->actingAs($admin)->post('/admin/account/password', ['current_password' => 'nope', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertSessionHasErrors('current_password');
        $this->actingAs($admin)->post('/admin/account/password', ['current_password' => 'secret-password-123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->post('/admin/account/password', ['current_password' => 'secret-password-123', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertRedirect('/admin/account');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword2026', $admin->fresh()->password));
    }
}
