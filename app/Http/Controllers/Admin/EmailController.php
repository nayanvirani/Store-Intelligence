<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Mail\EmailDelivery;
use App\Models\Mail\EmailProvider;
use App\Services\Mail\Drivers;
use App\Services\Mail\EmailSender;
use App\Services\Mail\OutgoingEmail;
use App\Support\EmailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Email providers: the services emails go out through (several at once, with failover),
 * the default sender, and the delivery log.
 */
class EmailController extends Controller
{
    public function index(EmailSender $sender): View
    {
        $providers = EmailProvider::orderBy('priority')->orderBy('id')->get()->each->rollUsage();
        $today = now()->startOfDay();

        return view('admin.email.index', [
            'settings' => EmailSettings::get(),
            'providers' => $providers,
            'next' => $sender->candidates(EmailSettings::get()['strategy'])->first(),
            'stats' => [
                'sent' => EmailDelivery::where('status', 'sent')->where('created_at', '>=', $today)->count(),
                'failed' => EmailDelivery::where('status', 'failed')->where('created_at', '>=', $today)->count(),
                'waiting' => \Illuminate\Support\Facades\DB::table('jobs')->where('payload', 'like', '%SendQueuedMailable%')->count(),
                'capacity' => $providers->where('is_active', true)->sum(fn ($p) => $p->daily_limit === null ? null : max(0, $p->daily_limit - $p->sent_today)),
                'unlimited' => $providers->where('is_active', true)->contains(fn ($p) => $p->daily_limit === null),
            ],
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'strategy' => ['required', Rule::in(array_keys(EmailSettings::STRATEGIES))],
            'from_email' => ['nullable', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:190'],
        ]);
        EmailSettings::save(['enabled' => $request->boolean('enabled')] + array_map(fn ($v) => (string) $v, $data));
        AuditLog::record('admin.email_settings_saved', null, ['by' => $request->user()->email]);

        return back()->with('status', 'Email settings saved.');
    }

    public function create(Request $request): View
    {
        $driver = $request->query('driver');
        if (! isset(Drivers::ALL[$driver])) {
            return view('admin.email.pick');
        }
        [$daily, $monthly] = Drivers::ALL[$driver]['limits'];

        return view('admin.email.edit', ['provider' => new EmailProvider([
            'driver' => $driver, 'name' => \Illuminate\Support\Str::before(Drivers::ALL[$driver]['label'], ' ('),
            'daily_limit' => $daily, 'monthly_limit' => $monthly, 'is_active' => true,
            'priority' => (int) EmailProvider::max('priority') + 10,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $driver = $request->input('driver');
        abort_unless(isset(Drivers::ALL[$driver]), 404);
        $provider = new EmailProvider(['driver' => $driver]);
        $this->fill($provider, $request, true)->save();
        AuditLog::record('admin.email_provider_added', null, ['provider' => $provider->name, 'driver' => $driver, 'by' => $request->user()->email]);

        return redirect()->route('admin.email')->with('status', "{$provider->name} added. Send a test email to check the key and sender.");
    }

    public function edit(int $provider): View
    {
        return view('admin.email.edit', ['provider' => EmailProvider::findOrFail($provider)]);
    }

    public function update(Request $request, int $provider): RedirectResponse
    {
        $provider = EmailProvider::findOrFail($provider);
        // Saving clears a pause: the usual fix for a failing provider is a new key or sender.
        $this->fill($provider, $request, false)->forceFill(['status' => 'ok', 'paused_until' => null, 'consecutive_failures' => 0])->save();
        AuditLog::record('admin.email_provider_updated', null, ['provider' => $provider->name, 'by' => $request->user()->email]);

        return redirect()->route('admin.email')->with('status', "{$provider->name} saved.");
    }

    public function test(Request $request, EmailSender $sender, int $provider): RedirectResponse
    {
        $provider = EmailProvider::findOrFail($provider);
        $to = $request->validate(['to' => ['required', 'email']])['to'];
        $result = $sender->send(OutgoingEmail::fromText($to, 'Test email from Store Intelligence', "This is a test from the Store Intelligence super admin.\n\nIt was sent through {$provider->name} ({$provider->label()}). If you can read this, the key and sender address work.", ['category' => 'test']), $provider);

        return $result->ok()
            ? back()->with('status', "Test email sent to {$to} through {$provider->name}. Check the inbox (and spam).")
            : back()->withErrors(['test' => "{$provider->name} couldn't send: {$result->error}"]);
    }

    public function action(Request $request, int $provider, string $action): RedirectResponse
    {
        $provider = EmailProvider::findOrFail($provider);
        $message = match ($action) {
            'resume' => tap('Resumed: '.$provider->name.' will be used again.', fn () => $provider->forceFill(['status' => 'ok', 'paused_until' => null, 'consecutive_failures' => 0])->save()),
            'toggle' => tap($provider->is_active ? "{$provider->name} switched off." : "{$provider->name} switched on.", fn () => $provider->forceFill(['is_active' => ! $provider->is_active])->save()),
            'up', 'down' => tap('Order saved.', fn () => $this->move($provider, $action)),
            'delete' => tap("{$provider->name} removed.", fn () => $provider->delete()),
        };
        AuditLog::record('admin.email_provider_'.$action, null, ['provider' => $provider->name, 'by' => $request->user()->email]);

        return redirect()->route('admin.email')->with('status', $message);
    }

    public function log(Request $request): View
    {
        $deliveries = EmailDelivery::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('provider'), fn ($q, $p) => $q->where('email_provider_id', $p))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('to_email', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.email.log', ['deliveries' => $deliveries, 'providers' => EmailProvider::orderBy('priority')->pluck('name', 'id')]);
    }

    private function move(EmailProvider $provider, string $direction): void
    {
        $list = EmailProvider::orderBy('priority')->orderBy('id')->get()->values();
        $i = $list->search(fn ($p) => $p->id === $provider->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $list->count()) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
        $list->values()->each(fn ($p, $n) => $p->forceFill(['priority' => ($n + 1) * 10])->save());
    }

    private function fill(EmailProvider $provider, Request $request, bool $creating): EmailProvider
    {
        $fields = Drivers::ALL[$provider->driver]['fields'];
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'from_email' => ['nullable', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'monthly_limit' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
        foreach ($fields as $key => [$label, $secret]) {
            // Saved secrets are never shown; leaving the field empty keeps the saved value.
            $rules["credentials.{$key}"] = [$creating && $secret ? 'required' : 'nullable', 'string', 'max:500'];
            if ($format = Drivers::KEY_FORMATS["{$provider->driver}.{$key}"] ?? null) {
                $rules["credentials.{$key}"][] = function ($attribute, $value, $fail) use ($format, $label) {
                    if (trim((string) $value) !== '' && ! preg_match($format[0], trim((string) $value))) {
                        $fail("That {$label} doesn't look right (".strlen(trim((string) $value)).' characters). '.$format[1]);
                    }
                };
            }
        }
        $data = $request->validate($rules, [], collect($fields)->mapWithKeys(fn ($f, $k) => ["credentials.{$k}" => strtolower($f[0])])->all());

        $credentials = $creating ? Drivers::defaults($provider->driver) : ($provider->credentials ?? []);
        foreach ($fields as $key => [$label, $secret]) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            if ($value !== '' || ! $secret) {
                $credentials[$key] = $value !== '' ? $value : ($credentials[$key] ?? '');
            }
        }

        return $provider->fill([
            'name' => $data['name'], 'credentials' => $credentials,
            'from_email' => $data['from_email'] ?? null, 'from_name' => $data['from_name'] ?? null,
            'daily_limit' => $data['daily_limit'] ?? null, 'monthly_limit' => $data['monthly_limit'] ?? null,
            'priority' => (int) ($data['priority'] ?? $provider->priority ?? 10), 'is_active' => $request->boolean('is_active'),
        ]);
    }
}
