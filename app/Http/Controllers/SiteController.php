<?php

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use App\Support\Legal;
use App\Support\PlanCatalog;
use App\Support\PricingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * The public website: a landing page, pricing from the plans in the super admin, a contact form and
 * the legal pages Shopify requires (edited in the super admin).
 */
class SiteController extends Controller
{
    public function home(): View
    {
        return view('site.home', ['plans' => PlanCatalog::cards(), 'pricing' => PricingPage::get()]);
    }

    public function pricing(): View
    {
        return view('site.pricing', ['plans' => PlanCatalog::cards(), 'compare' => PlanCatalog::compare(), 'pricing' => PricingPage::get()]);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /app\nDisallow: /admin\nDisallow: /webhooks\nSitemap: ".route('site.sitemap')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = array_merge([route('site.home'), route('site.pricing'), route('site.contact'), route('site.legal.index')], array_map(fn ($p) => Legal::url($p['slug']), Legal::pages()));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', array_map(fn ($u) => '<url><loc>'.e($u).'</loc></url>', array_unique($urls))).'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function contact(): View
    {
        return view('site.contact');
    }

    public function submitContact(Request $request): RedirectResponse
    {
        // Honeypot: bots fill every field.
        if ($request->filled('website')) {
            return back()->with('contact_sent', true);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email:rfc|max:190',
            'company' => 'nullable|string|max:160',
            'store_url' => 'nullable|string|max:190',
            'topic' => 'required|in:Sales,Support,Partnership,Other',
            'message' => 'required|string|max:5000',
        ], [
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'message.required' => 'Please add a message.',
        ]);

        try {
            ContactSubmission::create($data + ['ip' => $request->ip()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('contact_failed', true);
        }

        return back()->with('contact_sent', true);
    }

    public function privacy(): View
    {
        return $this->legal('privacy');
    }

    public function terms(): View
    {
        return $this->legal('terms');
    }

    public function dpa(): View
    {
        return $this->legal('dpa');
    }

    public function legalIndex(): View
    {
        return view('site.legal-index', ['pages' => Legal::pages()]);
    }

    public function legal(string $slug): View
    {
        $page = \App\Models\LegalPage::where('slug', $slug)->where('is_published', true)->firstOrFail();
        $effective = $page->effective_at?->toFormattedDateString();

        return view('site.legal', ['page' => $page, 'effective' => $effective, 'pages' => Legal::pages()] + Legal::render($page->body, $effective));
    }
}
