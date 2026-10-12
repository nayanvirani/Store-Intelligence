<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LegalPage;
use App\Models\Store;
use App\Support\Legal;
use App\Support\LegalDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Legal & policies: the business details every policy quotes, and each page's text.
 * Edits are saved as a draft; publishing makes a new version, kept for the record.
 */
class LegalController extends Controller
{
    /** Pages the App Store listing and the app itself link to: always published. */
    private const CORE = ['terms', 'privacy', 'dpa'];

    public function index(): View
    {
        $installed = Store::whereNotNull('installed_at')->whereNull('uninstalled_at')->get(['id', 'legal_acks']);

        return view('admin.legal.index', [
            'details' => Legal::details(),
            'missing' => Legal::missing(),
            'pages' => LegalPage::orderBy('position')->orderBy('id')->get(),
            'installed' => $installed->count(),
            'acked' => fn (LegalPage $page) => $installed->filter(fn ($s) => (int) ($s->legal_acks[$page->slug]['version'] ?? 0) >= (int) $page->review_version)->count(),
        ]);
    }

    public function saveDetails(Request $request): RedirectResponse
    {
        $rules = collect(Legal::DETAILS)->map(fn () => ['nullable', 'string', 'max:300'])->all();
        $rules['contact_email'] = ['nullable', 'email', 'max:190'];
        $rules['privacy_email'] = ['nullable', 'email', 'max:190'];
        $rules['notice_days'] = ['nullable', 'integer', 'min:0', 'max:180'];
        $data = $request->validate($rules);
        // Defaults fill anything left empty that has one.
        foreach (Legal::DETAILS as $key => [, $default]) {
            if (($data[$key] ?? '') === '' && $default !== '' && $key !== 'legal_name') {
                $data[$key] = $default;
            }
        }
        Legal::saveDetails($data);
        AuditLog::record('admin.legal_details_saved', null, ['by' => $request->user()->email]);

        return back()->with('status', 'Business details saved. Every policy uses them straight away.');
    }

    public function create(): View
    {
        return view('admin.legal.edit', ['page' => new LegalPage(['show_in_footer' => true, 'is_published' => true, 'position' => LegalPage::max('position') + 1, 'body' => '']), 'core' => false, 'hasDefault' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:60', Rule::unique('legal_pages', 'slug')],
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:200000'],
        ]);
        $page = LegalPage::create($data + ['version' => 0, 'is_published' => false, 'show_in_footer' => $request->boolean('show_in_footer'), 'position' => LegalPage::max('position') + 1, 'draft_title' => $data['title'], 'draft_body' => $data['body'], 'updated_by' => $request->user()->email]);
        AuditLog::record('admin.legal_page_created', null, ['page' => $page->slug, 'by' => $request->user()->email]);

        return redirect()->route('admin.legal.edit', $page->id)->with('status', 'Page created as a draft. Publish it when it\'s ready.');
    }

    public function edit(int $page): View
    {
        $page = LegalPage::findOrFail($page);

        return view('admin.legal.edit', ['page' => $page, 'core' => in_array($page->slug, self::CORE, true), 'hasDefault' => isset(LegalDefaults::pages()[$page->slug])]);
    }

    public function update(Request $request, int $page): RedirectResponse
    {
        $page = LegalPage::findOrFail($page);
        $intent = $request->input('intent', 'draft');
        if ($intent === 'discard') {
            $page->forceFill(['draft_title' => null, 'draft_body' => null])->save();

            return back()->with('status', 'Draft discarded.');
        }
        if ($intent === 'default') {
            $default = LegalDefaults::pages()[$page->slug] ?? abort(404);
            $page->forceFill(['draft_title' => $default[0], 'draft_body' => $default[4]])->save();

            return back()->with('status', 'The original text is loaded as a draft. Review it, then publish or discard.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:200000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'effective_at' => [$intent === 'publish' ? 'required' : 'nullable', 'date'],
            'change_summary' => ['nullable', 'string', 'max:500'],
            'intent' => ['required', 'in:draft,publish'],
        ]);
        $core = in_array($page->slug, self::CORE, true);
        // Settings that aren't the text apply at once.
        $page->fill([
            'summary' => $data['summary'] ?? null, 'position' => (int) ($data['position'] ?? $page->position),
            'show_in_footer' => $request->boolean('show_in_footer'), 'is_published' => $core || $request->boolean('is_published'),
            'updated_by' => $request->user()->email,
        ]);

        if ($intent === 'draft') {
            $page->forceFill(['draft_title' => $data['title'], 'draft_body' => $data['body']])->save();

            return back()->with('status', 'Draft saved. The live page hasn\'t changed.');
        }

        $changed = $data['body'] !== $page->body || $data['title'] !== $page->title || $page->version === 0;
        if ($changed) {
            $page->version++;
        }
        $page->forceFill([
            'title' => $data['title'], 'body' => $data['body'], 'draft_title' => null, 'draft_body' => null,
            'effective_at' => $data['effective_at'], 'is_published' => true,
        ]);
        // Ask merchants to review this version; earlier requests stay until they do.
        if ($changed && $request->boolean('ask_review')) {
            $page->forceFill(['requires_review' => true, 'review_version' => $page->version]);
        }
        $page->save();
        if ($changed) {
            $page->versions()->create([
                'version' => $page->version, 'title' => $page->title, 'body' => $page->body, 'change_summary' => $data['change_summary'] ?? null,
                'effective_at' => $data['effective_at'], 'published_by' => $request->user()->email, 'published_at' => now(),
            ]);
        }
        AuditLog::record('admin.legal_page_published', null, ['page' => $page->slug, 'version' => $page->version, 'changed' => $changed, 'ask_review' => $request->boolean('ask_review'), 'by' => $request->user()->email]);

        return back()->with('status', $changed
            ? "Version {$page->version} of “{$page->title}” is live.".($request->boolean('ask_review') ? ' Merchants will see a notice in the app asking them to review it.' : '')
            : 'Saved. The text didn\'t change, so no new version was made.');
    }

    /** Opens the page as merchants will see it, from the editor's current text (nothing is saved). */
    public function preview(Request $request, int $page): View
    {
        $page = LegalPage::findOrFail($page);
        $preview = $page->replicate()->fill(['title' => (string) $request->input('title', $page->title), 'body' => (string) $request->input('body', $page->body)]);
        $preview->slug = $page->slug;
        $effective = $request->date('effective_at')?->toFormattedDateString() ?? $page->effective_at?->toFormattedDateString();

        return view('site.legal', ['page' => $preview, 'effective' => $effective, 'pages' => Legal::pages()] + Legal::render($preview->body, $effective));
    }

    public function version(int $page, int $version): View
    {
        $page = LegalPage::findOrFail($page);
        $row = $page->versions()->where('version', $version)->firstOrFail();

        return view('admin.legal.version', ['page' => $page, 'row' => $row] + Legal::render($row->body, $row->effective_at?->toFormattedDateString()));
    }

    public function restore(int $page, int $version): RedirectResponse
    {
        $page = LegalPage::findOrFail($page);
        $row = $page->versions()->where('version', $version)->firstOrFail();
        $page->forceFill(['draft_title' => $row->title, 'draft_body' => $row->body])->save();

        return redirect()->route('admin.legal.edit', $page->id)->with('status', "Version {$version} is loaded as a draft. Publish it to make it live again.");
    }
}
