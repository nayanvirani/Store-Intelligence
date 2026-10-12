<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\User;
use App\Support\Legal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
    }

    private function admin(string $role = 'super_admin', string $email = 'ava@orderorbit.space'): User
    {
        return User::forceCreate(['name' => 'Ava', 'email' => $email, 'password' => 'secret-password-123', 'is_admin' => true, 'admin_role' => $role]);
    }

    public function test_policies_are_public_with_the_operators_details_filled_in(): void
    {
        $terms = $this->get('/terms')->assertOk()->assertSee('Terms of Service')->assertSee('Nayan Virani')->assertSee('individual developer')
            ->assertSee('Limitation of liability')->assertSee('the laws of the country where the developer lives')->assertSee('Version 1');
        $this->assertStringNotContainsString('{{', $terms->getContent());
        $this->get('/privacy')->assertOk()->assertSee('Orders, products and synced metrics')->assertSee('Digital Personal Data Protection Act');
        $this->get('/dpa')->assertOk()->assertSee('Standard Contractual Clauses');
        foreach (['billing', 'acceptable-use', 'cookies', 'subprocessors', 'support'] as $slug) {
            $this->get("/legal/{$slug}")->assertOk();
        }
        $this->get('/legal')->assertOk()->assertSee('Billing, Cancellation &amp; Refund Policy', false)->assertSee('/legal/cookies');
        $this->get('/legal/nope')->assertNotFound();

        $this->get('/')->assertSee('Acceptable Use Policy')->assertSee('/legal/subprocessors');
        $this->get('/sitemap.xml')->assertSee('/legal/support')->assertSee('/privacy');
    }

    public function test_super_admin_edits_details_and_publishes_versions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/legal')->assertOk()->assertSee('Fill in before launch')->assertSee('Country you live in');

        $this->actingAs($admin)->post('/admin/legal/details', ['legal_name' => 'Nayan Virani', 'country' => 'India', 'city' => 'Surat, Gujarat', 'contact_email' => 'support@orderorbit.space'])->assertRedirect();
        $this->get('/terms')->assertSee('the laws of India')->assertSee('the courts of Surat, Gujarat, India')->assertSee('mailto:support@orderorbit.space', false)->assertSee('Based in Surat, Gujarat, India.');
        $this->assertSame([], Legal::missing());
        $this->assertSame('2 business days', Legal::details()['response_time'], 'Empty fields keep their defaults.');

        // A draft doesn't change the live page.
        $page = LegalPage::where('slug', 'billing')->sole();
        $body = $page->body."\n\n## 9. Annual plans\n\nAnnual plans are refundable within **14 days**.";
        $this->actingAs($admin)->get("/admin/legal/{$page->id}")->assertOk()->assertSee('Versions');
        $this->actingAs($admin)->post("/admin/legal/{$page->id}", ['intent' => 'draft', 'title' => $page->title, 'body' => $body, 'show_in_footer' => '1'])->assertRedirect();
        $this->get('/legal/billing')->assertDontSee('Annual plans');
        $this->assertTrue($page->fresh()->hasDraft());
        $this->actingAs($admin)->post("/admin/legal/{$page->id}/preview", ['title' => $page->title, 'body' => $body])->assertOk()->assertSee('Annual plans');

        // Publishing makes version 2 live and keeps version 1.
        $this->actingAs($admin)->post("/admin/legal/{$page->id}", ['intent' => 'publish', 'title' => $page->title, 'body' => $body, 'effective_at' => '2026-11-01', 'change_summary' => 'Annual plans', 'show_in_footer' => '1'])->assertRedirect();
        $this->get('/legal/billing')->assertSee('Annual plans')->assertSee('Version 2')->assertSee('Nov 1, 2026');
        $page->refresh();
        $this->assertSame(2, $page->version);
        $this->assertFalse($page->hasDraft());
        $this->assertSame([2, 1], $page->versions->pluck('version')->all());
        $this->actingAs($admin)->get("/admin/legal/{$page->id}/versions/1")->assertOk()->assertDontSee('Annual plans');

        // An old version comes back as a draft; the original text too.
        $this->actingAs($admin)->post("/admin/legal/{$page->id}/versions/1/restore")->assertRedirect("/admin/legal/{$page->id}");
        $this->assertStringNotContainsString('Annual plans', $page->fresh()->draft_body);
        $this->actingAs($admin)->post("/admin/legal/{$page->id}", ['intent' => 'discard'])->assertRedirect();
        $this->assertFalse($page->fresh()->hasDraft());

        // The Terms and Privacy Policy can't be hidden; other pages can, and new pages start hidden.
        $terms = LegalPage::where('slug', 'terms')->sole();
        $this->actingAs($admin)->post("/admin/legal/{$terms->id}", ['intent' => 'draft', 'title' => $terms->title, 'body' => $terms->body])->assertRedirect();
        $this->assertTrue($terms->fresh()->is_published);
        $this->actingAs($admin)->post('/admin/legal', ['slug' => 'shipping', 'title' => 'Shipping Policy', 'body' => "## Shipping\n\nNone, it's software."])->assertRedirect();
        $this->get('/legal/shipping')->assertNotFound();
        $shipping = LegalPage::where('slug', 'shipping')->sole();
        $this->actingAs($admin)->post("/admin/legal/{$shipping->id}", ['intent' => 'publish', 'title' => 'Shipping Policy', 'body' => $shipping->draft_body, 'effective_at' => now()->toDateString()])->assertRedirect();
        $this->get('/legal/shipping')->assertOk()->assertSee("it's software", false)->assertSee('Version 1');

        // Only Super Admins edit legal pages.
        $ops = $this->admin('operations', 'ops@orderorbit.space');
        $this->actingAs($ops)->get('/admin/legal')->assertForbidden();
        $this->actingAs($ops)->post('/admin/legal/details', ['legal_name' => 'X'])->assertForbidden();
    }

    public function test_merchants_are_asked_to_review_material_changes_and_it_is_recorded(): void
    {
        $store = $this->installedStore();
        Legal::acknowledge($store, 'install');
        $owner = $this->member($store, 'owner');
        $this->page('/app/settings/privacy', $owner)->assertOk()->assertJsonPath('shared.legal', null)
            ->assertJsonPath('props.policies.0.title', 'Terms of Service')->assertJsonPath('props.policies.0.accepted.via', 'install');

        // A new Terms version, published with "Ask merchants to review".
        $admin = $this->admin();
        $terms = LegalPage::where('slug', 'terms')->sole();
        $this->actingAs($admin)->post("/admin/legal/{$terms->id}", ['intent' => 'publish', 'title' => $terms->title, 'body' => $terms->body."\n\nNew clause.", 'effective_at' => '2026-12-01', 'ask_review' => '1'])->assertRedirect();
        $this->page('/app/settings/privacy', $owner)->assertJsonPath('shared.legal.pages.0.slug', 'terms')->assertJsonPath('shared.legal.pages.0.effective', 'Dec 1, 2026');
        $this->actingAs($admin)->get('/admin/legal')->assertSee('0 of 1 reviewed v2');

        $this->send('/app/legal/acknowledge', [], $owner)->assertOk();
        $this->page('/app/settings/privacy', $owner)->assertJsonPath('shared.legal', null);
        $ack = $store->fresh()->legal_acks['terms'];
        $this->assertSame([2, 'in_app'], [$ack['version'], $ack['via']]);
        $this->assertStringContainsString($owner->email, $ack['by']);
        $this->actingAs($admin)->get('/admin/legal')->assertSee('1 of 1 reviewed v2');
        $this->actingAs($admin)->get("/admin/stores/{$store->id}")->assertSee('Terms v2')->assertSee('Last reviewed in the app');

        // A small fix published without asking doesn't bother anyone.
        $privacy = LegalPage::where('slug', 'privacy')->sole();
        $this->actingAs($admin)->post("/admin/legal/{$privacy->id}", ['intent' => 'publish', 'title' => $privacy->title, 'body' => $privacy->body.' Typo fixed.', 'effective_at' => now()->toDateString()])->assertRedirect();
        $this->page('/app/settings/privacy', $owner)->assertJsonPath('shared.legal', null);
    }
}
