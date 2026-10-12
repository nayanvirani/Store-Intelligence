<?php

namespace App\Support;

use App\Models\LegalPage;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Legal & policy pages: the business details they quote, {{placeholder}} filling, Markdown
 * rendering, the cached list of published pages, and which updates merchants must review.
 */
class Legal
{
    private const PAGES_KEY = 'legal-pages:v1';

    private const DETAILS_KEY = 'legal-details:v1';

    /** Business details: key => [label, default, help, required before launch] */
    public const DETAILS = [
        'legal_name' => ['Your full legal name', 'Nayan Virani', 'Store Intelligence is your own app, not a company, so merchants contract with you as an individual developer.', true],
        'trading_name' => ['App name', 'Store Intelligence', null, false],
        'country' => ['Country you live in', '', 'Decides the governing law and courts, e.g. "India".', true],
        'city' => ['City and state (optional)', '', 'Narrows the courts, e.g. "Surat, Gujarat".', false],
        'contact_email' => ['Contact email', '', 'Shown in the policies for legal, billing and privacy questions. Use an app address (e.g. support@example.com), not a personal one. Empty: the contact page is used.', true],
        'privacy_email' => ['Privacy & grievance email (optional)', '', 'Empty: the contact email is used.', false],
        'address' => ['Postal address (optional)', '', 'Not required for an individual. Leave empty to keep your home address private; notices then go to the contact email.', false],
        'governing_law' => ['Governing law (optional override)', '', 'Empty: "the laws of <country>".', false],
        'courts' => ['Courts (optional override)', '', 'Empty: "the courts of <city>, <country>".', false],
        'support_hours' => ['Support hours', 'business days (Monday to Friday, excluding public holidays)', null, false],
        'response_time' => ['First-response target', '2 business days', null, false],
        'notice_days' => ['Notice before material changes (days)', '15', 'How long before changed Terms take effect.', false],
    ];

    /** Placeholders available in page bodies, for the editor's help text. */
    public const PLACEHOLDERS = [
        'app_name' => 'App / trading name',
        'operator' => 'Legal name',
        'contact_line' => 'Where you are based and how to reach you',
        'contact' => 'Contact email link (or the contact page)',
        'privacy_contact' => 'Privacy email link (or contact)',
        'governing_law' => 'Governing law',
        'courts' => 'Courts for disputes',
        'support_hours' => 'Support hours',
        'response_time' => 'First-response target',
        'notice_days' => 'Notice days',
        'website' => 'Website address',
        'effective_date' => 'This page\'s effective date',
        'url:terms' => 'Link to another legal page (any slug)',
    ];

    public static function details(): array
    {
        $saved = Cache::rememberForever(self::DETAILS_KEY, function () {
            try {
                $value = DB::table('platform_settings')->where('key', 'legal_details')->value('value');
            } catch (Throwable) {
                return [];
            }

            return $value ? (array) json_decode($value, true) : [];
        });

        return collect(self::DETAILS)->map(fn ($field, $key) => (string) ($saved[$key] ?? $field[1]))->all();
    }

    public static function saveDetails(array $details): void
    {
        $values = collect(self::DETAILS)->map(fn ($field, $key) => trim((string) ($details[$key] ?? '')))->all();
        DB::table('platform_settings')->updateOrInsert(['key' => 'legal_details'], ['value' => json_encode($values), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::DETAILS_KEY);
    }

    /** Required details still empty: these show as warnings in the admin. */
    public static function missing(): array
    {
        $details = self::details();

        return collect(self::DETAILS)->filter(fn ($field, $key) => $field[3] && $details[$key] === '')->map(fn ($field) => $field[0])->values()->all();
    }

    public static function url(string $slug): string
    {
        return in_array($slug, ['privacy', 'terms', 'dpa'], true) ? route('site.'.$slug) : route('site.legal', $slug);
    }

    /** Fills placeholders, then renders Markdown. Returns the HTML and a table of contents from the ## headings. */
    public static function render(string $markdown, ?string $effective = null): array
    {
        $d = self::details();
        $app = $d['trading_name'] ?: 'Store Intelligence';
        $link = fn (string $email) => "[{$email}](mailto:{$email})";
        $contact = $d['contact_email'] !== '' ? $link($d['contact_email']) : '[our contact page]('.route('site.contact').')';
        $values = [
            'app_name' => $app,
            'operator' => $d['legal_name'] ?: "the developer of {$app}",
            'contact_line' => self::contactLine($d, $contact),
            'contact' => $contact,
            'privacy_contact' => $d['privacy_email'] !== '' ? $link($d['privacy_email']) : $contact,
            'governing_law' => $d['governing_law'] ?: ($d['country'] !== '' ? "the laws of {$d['country']}" : 'the laws of the country where the developer lives'),
            'courts' => $d['courts'] ?: ($d['country'] !== '' ? 'the courts of '.($d['city'] !== '' ? "{$d['city']}, " : '').$d['country'] : 'the courts of the place where the developer lives'),
            'support_hours' => $d['support_hours'],
            'response_time' => $d['response_time'],
            'notice_days' => $d['notice_days'],
            'website' => rtrim(config('app.url'), '/'),
            'effective_date' => $effective ?? now()->toFormattedDateString(),
        ];
        $filled = preg_replace_callback('/\{\{\s*([a-z_]+(?::[a-z0-9-]+)?)\s*\}\}/', function ($m) use ($values) {
            if (str_starts_with($m[1], 'url:')) {
                return self::url(substr($m[1], 4));
            }

            return $values[$m[1]] ?? $m[0];
        }, $markdown);

        $html = (string) Str::markdown($filled, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $toc = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', function ($m) use (&$toc) {
            $text = trim(html_entity_decode(strip_tags($m[1])));
            $id = Str::slug(preg_replace('/^\d+\.\s*/', '', $text)) ?: 'section-'.(count($toc) + 1);
            $toc[] = [$id, $text];

            return "<h2 id=\"{$id}\">{$m[1]}</h2>";
        }, $html);
        // Wide tables scroll on phones instead of stretching the page.
        $html = str_replace(['<table>', '</table>'], ['<div class="legal-table"><table>', '</table></div>'], $html);

        return ['html' => new HtmlString($html), 'toc' => $toc];
    }

    /** "Based in X. Postal address: Y. Contact: Z." with only the parts that are filled in. */
    private static function contactLine(array $d, string $contact): string
    {
        $where = trim(($d['city'] !== '' ? $d['city'].', ' : '').$d['country'], ', ');

        return trim(($where !== '' ? "Based in {$where}. " : '').($d['address'] !== '' ? "Postal address: {$d['address']}. " : '')."Contact: {$contact}.");
    }

    /** Published pages, in order, without bodies. */
    public static function pages(): array
    {
        try {
            return Cache::rememberForever(self::PAGES_KEY, fn () => LegalPage::where('is_published', true)->orderBy('position')->orderBy('id')
                ->get(['slug', 'title', 'summary', 'version', 'effective_at', 'show_in_footer', 'requires_review', 'review_version', 'updated_at'])
                ->map(fn ($p) => [
                    'slug' => $p->slug, 'title' => $p->title, 'summary' => $p->summary, 'version' => $p->version,
                    'effective_at' => $p->effective_at?->toDateString(), 'footer' => $p->show_in_footer,
                    'review_version' => $p->requires_review ? $p->review_version : null, 'updated_at' => $p->updated_at?->toDateString(),
                ])->all());
        } catch (Throwable) {
            return [];
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::PAGES_KEY);
    }

    /** Pages updated since this store last acknowledged them, which merchants are asked to review. */
    public static function noticeFor(?Store $store): ?array
    {
        if ($store === null) {
            return null;
        }
        $acks = (array) ($store->legal_acks ?? []);
        $pending = collect(self::pages())->filter(fn ($p) => $p['review_version'] !== null && (int) ($acks[$p['slug']]['version'] ?? 0) < $p['review_version'])->values();
        if ($pending->isEmpty()) {
            return null;
        }

        return [
            'pages' => $pending->map(fn ($p) => ['slug' => $p['slug'], 'title' => $p['title'], 'url' => self::url($p['slug']), 'effective' => $p['effective_at'] ? \Illuminate\Support\Carbon::parse($p['effective_at'])->toFormattedDateString() : null])->all(),
        ];
    }

    /** Records the versions a store has accepted: all published pages on install, or the reviewed ones. */
    public static function acknowledge(Store $store, string $via, ?string $by = null, ?array $slugs = null): void
    {
        $acks = (array) ($store->legal_acks ?? []);
        foreach (self::pages() as $page) {
            if ($slugs === null || in_array($page['slug'], $slugs, true)) {
                $acks[$page['slug']] = ['version' => $page['version'], 'at' => now()->toIso8601String(), 'via' => $via, 'by' => $by];
            }
        }
        $store->forceFill(['legal_acks' => $acks])->save();
    }
}
