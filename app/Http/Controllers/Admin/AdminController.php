<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\App\SupportController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactSubmission;
use App\Models\FeatureFlag;
use App\Models\SyncRun;
use App\Models\Store;
use App\Models\Support\Attachment;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Models\WebhookReceipt;
use App\Services\Support\SupportDesk;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal Admin for the Store Intelligence team: stores, subscriptions, usage, data sync health,
 * failed background jobs, feature flags, support, website messages and audit logs. Separate from
 * the merchant app, behind an email-and-password team login.
 */
class AdminController extends Controller
{
    public function home(): View
    {
        $stores = Store::all();
        $installed = $stores->filter(fn ($s) => $s->isInstalled());
        $plans = collect(config('shopify.billing.plans'));
        // Paying = a real subscription (not a test store or a complimentary plan).
        $paying = $installed->filter(fn ($s) => $s->plan && ! $s->compPlan() && ! $s->isTestShop());
        $installsByDay = Store::where('installed_at', '>=', now()->subDays(29)->startOfDay())->selectRaw('date(installed_at) as day, count(*) as n')->groupBy('day')->pluck('n', 'day');

        return view('admin.home', [
            'kpis' => [
                'installed' => $installed->count(),
                'new_30d' => $stores->filter(fn ($s) => $s->installed_at?->gte(now()->subDays(30)))->count(),
                'uninstalled_30d' => $stores->filter(fn ($s) => $s->uninstalled_at?->gte(now()->subDays(30)))->count(),
                'mrr' => $paying->sum(fn ($s) => (float) ($plans[$s->plan]['price'] ?? 0)),
                'paying' => $paying->count(),
                'custom' => $installed->filter(fn ($s) => ! empty($s->entitlements))->count(),
                'upgrade_clicks_30d' => \App\Models\UpgradeEvent::where('created_at', '>=', now()->subDays(30))->count(),
                'upgrades_30d' => \App\Models\UpgradeEvent::where('converted_at', '>=', now()->subDays(30))->count(),
                'failed_jobs_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
                'failed_syncs_24h' => SyncRun::where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                'syncing' => SyncRun::whereIn('status', ['queued', 'running'])->count(),
                'queue' => DB::table('jobs')->count(),
                'open_tickets' => Ticket::whereIn('status', ['open'])->count(),
                'new_messages' => ContactSubmission::where('status', 'new')->count(),
                'webhooks_unprocessed' => WebhookReceipt::whereNull('processed_at')->where('created_at', '>=', now()->subDay())->count(),
            ],
            'planMix' => $plans->map(fn ($p, $key) => ['name' => $p['name'], 'stores' => $installed->filter(fn ($s) => $s->effectivePlan() === $key)->count(), 'mrr' => $paying->filter(fn ($s) => $s->plan === $key)->count() * (float) $p['price']]),
            'noPlan' => $installed->filter(fn ($s) => ! $s->hasPlanAccess())->count(),
            'installsByDay' => collect(range(29, 0))->mapWithKeys(fn ($d) => [now()->subDays($d)->toDateString() => (int) ($installsByDay[now()->subDays($d)->toDateString()] ?? 0)]),
            'recentInstalls' => Store::whereNotNull('installed_at')->latest('installed_at')->limit(8)->get(),
            'waiting' => Ticket::with('store')->where('status', 'open')->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->oldest('last_reply_at')->limit(8)->get(),
        ]);
    }

    public function stores(Request $request): View
    {
        $stores = Store::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($q) => $q->where('shop_domain', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")))
            ->when($request->query('status') === 'installed', fn ($q) => $q->whereNotNull('installed_at')->whereNull('uninstalled_at'))
            ->when($request->query('status') === 'uninstalled', fn ($q) => $q->whereNotNull('uninstalled_at'))
            ->when($request->query('status') === 'custom', fn ($q) => $q->whereNotNull('entitlements'))
            ->when($request->query('plan'), fn ($q, $plan) => $plan === 'none' ? $q->whereNull('plan') : $q->where('plan', $plan))
            ->latest('installed_at')->paginate(50)->withQueryString();
        $ids = $stores->pluck('id');

        return view('admin.stores', [
            'stores' => $stores,
            'plans' => collect(config('shopify.billing.plans'))->map(fn ($p) => $p['name']),
            'orders' => \App\Models\Order::whereIn('store_id', $ids)->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id'),
            'lastSync' => SyncRun::whereIn('store_id', $ids)->where('status', 'done')->selectRaw('store_id, max(finished_at) as at')->groupBy('store_id')->pluck('at', 'store_id'),
            'failedSync' => SyncRun::whereIn('store_id', $ids)->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->distinct()->pluck('store_id')->flip(),
        ]);
    }

    public function store(int $store): View
    {
        $store = Store::findOrFail($store);

        return view('admin.store', [
            'store' => $store,
            'tab' => in_array(request('tab'), ['overview', 'access', 'activity'], true) ? request('tab') : 'overview',
            'plans' => collect(config('shopify.billing.plans')),
            'meters' => \App\Services\Usage::METERS,
            'usageNow' => collect(app(\App\Services\Usage::class)->summary($store))->keyBy('meter'),
            'users' => $store->users()->orderBy('role')->get(),
            'subscription' => \App\Models\Subscription::where('store_id', $store->id)->latest('id')->first(),
            'usage' => \App\Models\UsageRecord::where('store_id', $store->id)->latest('id')->limit(20)->get(),
            'syncStatus' => app(\App\Services\Sync\SyncManager::class)->status($store),
            'syncRuns' => SyncRun::where('store_id', $store->id)->latest('id')->limit(15)->get(),
            'data' => [
                'products' => $store->products()->whereNull('deleted_at')->count(),
                'variants' => $store->variants()->whereNull('deleted_at')->count(),
                'missing_costs' => $store->variants()->whereNull('deleted_at')->whereNull('app_cost')->whereNull('shopify_cost')->count(),
                'orders' => $store->orders()->count(),
                'oldest_order' => $store->orders()->min('processed_at'),
                'cost_rules' => $store->costRules()->count(),
            ],
            'webhooks' => WebhookReceipt::where('shop_domain', $store->shop_domain)->latest('id')->limit(15)->get(),
            'tickets' => Ticket::where('store_id', $store->id)->latest('id')->limit(10)->get(),
            'audit' => AuditLog::where('store_id', $store->id)->latest('id')->limit(25)->get(),
        ]);
    }

    /** Background jobs that failed after their retries (Laravel failed_jobs). */
    public function failures(): View
    {
        $jobs = DB::table('failed_jobs')->latest('failed_at')->paginate(50);
        $jobs->setCollection($jobs->getCollection()->map(function ($job) {
            $payload = json_decode($job->payload, true) ?: [];

            return (object) [
                'id' => $job->id, 'uuid' => $job->uuid, 'queue' => $job->queue, 'failed_at' => \Illuminate\Support\Carbon::parse($job->failed_at),
                'name' => $payload['displayName'] ?? 'Job', 'error' => strtok((string) $job->exception, "\n"),
            ];
        }));

        return view('admin.failures', [
            'jobs' => $jobs,
            'byJob' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->get()
                ->countBy(fn ($j) => json_decode($j->payload, true)['displayName'] ?? 'Job')->sortDesc(),
        ]);
    }

    public function retryJob(int $id): RedirectResponse
    {
        $uuid = DB::table('failed_jobs')->where('id', $id)->value('uuid');
        abort_unless($uuid, 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        AuditLog::record('admin.job_retried', null, ['job' => $uuid, 'by' => auth()->user()->email]);

        return back()->with('status', 'Job queued again.');
    }

    public function forgetJob(int $id): RedirectResponse
    {
        DB::table('failed_jobs')->where('id', $id)->delete();

        return back()->with('status', 'Failed job removed.');
    }

    /** Data sync health across stores (Final MVP F7, F42). */
    public function sync(Request $request): View
    {
        $status = in_array($request->query('status'), ['queued', 'running', 'done', 'failed'], true) ? $request->query('status') : null;

        return view('admin.sync', [
            'runs' => SyncRun::with('store:id,shop_domain,name')->when($status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))->latest('id')->paginate(50)->withQueryString(),
            'counts' => SyncRun::where('created_at', '>=', now()->subDay())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'queue' => DB::table('jobs')->count(),
            'status' => $status,
        ]);
    }

    public function flags(): View
    {
        return view('admin.flags', ['flags' => FeatureFlag::orderBy('key')->get()]);
    }

    public function saveFlag(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'regex:/^[a-z0-9_.-]{2,60}$/'], 'description' => ['nullable', 'string', 'max:300'], 'store_domains' => ['nullable', 'string', 'max:5000']]);
        $ids = collect(preg_split('/[\s,]+/', (string) ($data['store_domains'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($d) => Store::where('shop_domain', strtolower(trim($d)))->value('id'))->filter()->values()->all();
        $flag = FeatureFlag::updateOrCreate(['key' => $data['key']], ['description' => $data['description'] ?? null, 'enabled' => $request->boolean('enabled'), 'store_ids' => $ids ?: null]);
        Features::forget($flag->key);
        AuditLog::record('admin.flag_saved', null, ['flag' => $flag->key, 'enabled' => $flag->enabled, 'stores' => count($ids), 'by' => auth()->user()->email]);

        return redirect()->route('admin.flags')->with('status', 'Flag saved.');
    }

    public function deleteFlag(int $flag): RedirectResponse
    {
        $flag = FeatureFlag::findOrFail($flag);
        Features::forget($flag->key);
        $flag->delete();

        return redirect()->route('admin.flags')->with('status', 'Flag deleted.');
    }

    public function tickets(Request $request): View
    {
        $status = in_array($request->query('status'), ['open', 'pending', 'resolved', 'closed', 'all'], true) ? $request->query('status') : 'open';

        return view('admin.tickets', [
            'tickets' => Ticket::with(['store', 'assignee'])->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->latest('last_reply_at')->paginate(50)->withQueryString(),
            'status' => $status,
            'counts' => Ticket::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function ticket(int $ticket): View
    {
        $ticket = Ticket::with(['store', 'requester', 'messages.attachments'])->findOrFail($ticket);

        return view('admin.ticket', ['ticket' => $ticket, 'team' => User::where('is_admin', true)->orderBy('name')->get()]);
    }

    public function replyTicket(Request $request, int $ticket, SupportDesk $desk): RedirectResponse
    {
        $ticket = Ticket::findOrFail($ticket);
        $request->validate(['body' => ['required', 'string', 'max:10000']] + SupportDesk::ATTACHMENT_RULES);
        $desk->reply($ticket, 'team', $request->input('body'), (array) $request->file('attachments', []), $request->boolean('internal'), $request->user());

        return redirect()->route('admin.ticket', $ticket->id)->with('status', $request->boolean('internal') ? 'Note added.' : 'Reply sent.');
    }

    public function updateTicket(Request $request, int $ticket): RedirectResponse
    {
        $ticket = Ticket::findOrFail($ticket);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Ticket::STATUSES))], 'priority' => ['required', 'in:'.implode(',', array_keys(Ticket::PRIORITIES))],
            'assigned_to' => ['nullable', 'integer'], 'resolution' => ['nullable', 'string', 'max:2000'],
        ]);
        $ticket->forceFill([
            'status' => $data['status'], 'priority' => $data['priority'],
            'assigned_to' => User::where('is_admin', true)->whereKey($data['assigned_to'] ?? 0)->value('id'),
            'resolution' => $data['resolution'] ?? null,
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null,
        ])->save();

        return redirect()->route('admin.ticket', $ticket->id)->with('status', 'Ticket updated.');
    }

    public function attachment(int $attachment): Response
    {
        return SupportController::download(Attachment::findOrFail($attachment));
    }

    /** Messages from the public website's contact form. */
    public function messages(Request $request): View
    {
        $status = in_array($request->query('status'), ['new', 'done', 'all'], true) ? $request->query('status') : 'new';

        return view('admin.messages', [
            'messages' => ContactSubmission::when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('id')->paginate(50)->withQueryString(),
            'status' => $status,
            'counts' => ContactSubmission::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function updateMessage(Request $request, int $message): RedirectResponse
    {
        $message = ContactSubmission::findOrFail($message);
        $message->forceFill(['status' => $request->input('status') === 'done' ? 'done' : 'new'])->save();

        return back()->with('status', 'Message updated.');
    }

    public function audit(Request $request): View
    {
        return view('admin.audit', [
            'logs' => AuditLog::with('store:id,shop_domain')
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($request->query('store'), fn ($q, $d) => $q->whereHas('store', fn ($q) => $q->where('shop_domain', 'like', "%{$d}%")))
                ->latest('id')->paginate(100)->withQueryString(),
        ]);
    }
}
