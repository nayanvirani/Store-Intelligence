<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Railway hosting costs for Finance, from Railway's public API.
 *
 * The bill: the workspace's invoices (what was charged, after credits) and, for the running period,
 * an estimate of the next invoice (the plan's minimum, or the usage so far when higher).
 * Railway bills the whole workspace, which can hold several projects; Finance counts only this
 * project's cost: its part of the workspace's resource usage (CPU, memory, network, volumes) in
 * the period each invoice covers, applied to what Railway actually charged.
 *
 * Token: a workspace or account token from railway.com/account/tokens, saved (encrypted) in the
 * Internal Admin or set as the RAILWAY_API_TOKEN variable.
 */
class RailwayBilling
{
    public const ENDPOINT = 'https://backboard.railway.com/graphql/v2';

    private const KEY = 'railway';

    private const CACHE = 'railway-billing:v2';

    /** What each plan charges at least per period (it includes that much usage). */
    public const PLAN_MINIMUM = ['FREE' => 0.0, 'HOBBY' => 5.0, 'PRO' => 20.0];

    /** Railway's unit prices, used only to weigh usage when splitting a bill between projects. */
    private const WEIGHTS = ['CPU_USAGE' => 20 / 43200, 'MEMORY_USAGE_GB' => 10 / 43200, 'NETWORK_TX_GB' => 0.05, 'DISK_USAGE_GB' => 0.15 / 43200, 'BACKUP_USAGE_GB' => 0.15 / 43200];

    /** @return array{token: ?string, project_id: ?string, from_env: bool} */
    public static function settings(): array
    {
        $saved = [];
        try {
            $row = Schema::hasTable('platform_settings') ? DB::table('platform_settings')->where('key', self::KEY)->value('value') : null;
            $saved = $row ? (array) json_decode($row, true) : [];
        } catch (Throwable) {
            // No database yet.
        }
        $token = null;
        if (! empty($saved['token'])) {
            try {
                $token = Crypt::decryptString($saved['token']);
            } catch (Throwable) {
                $token = null;
            }
        }
        $env = config('services.railway.token') ?: null;

        return [
            'token' => $token ?: $env,
            // The project this app runs in (Railway sets RAILWAY_PROJECT_ID), unless chosen in the admin.
            'project_id' => ($saved['project_id'] ?? null) ?: (config('services.railway.project_id') ?: null),
            'from_env' => ! $token && $env,
        ];
    }

    public static function connected(): bool
    {
        return (bool) self::settings()['token'];
    }

    /** Saves the token (encrypted; empty keeps the current one) and the project. */
    public static function save(?string $token, ?string $projectId = null, bool $forget = false): void
    {
        $row = DB::table('platform_settings')->where('key', self::KEY)->value('value');
        $current = $row ? (array) json_decode($row, true) : [];
        $uuid = fn ($v) => preg_match('/^[a-f0-9-]{36}$/', trim((string) $v)) ? trim($v) : null;
        $value = [
            'token' => $forget ? null : (trim((string) $token) !== '' ? Crypt::encryptString(trim($token)) : ($current['token'] ?? null)),
            'project_id' => $uuid($projectId),
        ];
        DB::table('platform_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::CACHE);
    }

    public static function refresh(): void
    {
        Cache::forget(self::CACHE);
    }

    /** The project to count when none is set: the only one the token can list, if there's just one. */
    private static function onlyProject(string $token): ?string
    {
        foreach (['{ projects { edges { node { id } } } }' => 'projects', '{ me { workspaces { projects { edges { node { id } } } } } }' => 'me'] as $q => $root) {
            try {
                $data = self::query($token, $q);
            } catch (Throwable) {
                continue;
            }
            $ids = $root === 'projects'
                ? collect($data['projects']['edges'] ?? [])->pluck('node.id')
                : collect($data['me']['workspaces'] ?? [])->flatMap(fn ($w) => collect($w['projects']['edges'] ?? [])->pluck('node.id'));

            return $ids->count() === 1 ? $ids->first() : null;
        }

        return null;
    }

    private static function query(string $token, string $query, array $variables = []): array
    {
        $response = Http::timeout(20)->withToken($token)->acceptJson()->post(self::ENDPOINT, ['query' => $query, 'variables' => (object) $variables]);
        $body = $response->json() ?? [];
        if (! $response->successful() || ! empty($body['errors'])) {
            throw new \RuntimeException($body['errors'][0]['message'] ?? ('HTTP '.$response->status()));
        }

        return $body['data'] ?? [];
    }

    /**
     * Billing for the workspace and this project's share of each invoice. Cached for an hour.
     *
     * @return array{ok: bool, error?: string, workspace?: string, plan?: string, projects?: array<string, string>, project?: string, project_id?: string, period?: array, usage?: float, credit?: float, share_now?: ?float, invoices?: list<array>, fetched_at?: string}
     */
    public static function data(): array
    {
        $s = self::settings();
        if (! $s['token']) {
            return ['ok' => false, 'error' => 'not_connected'];
        }

        return Cache::remember(self::CACHE, 3600, function () use ($s) {
            try {
                $projectId = $s['project_id'] ?: self::onlyProject($s['token']);
                if (! $projectId) {
                    return ['ok' => false, 'error' => 'Choose the Railway project to count (its ID is in the project\'s settings on Railway).'];
                }
                // The project leads to its workspace, which holds the bill (works with workspace and account tokens).
                $project = self::query($s['token'], 'query($id: String!) { project(id: $id) { id name workspaceId } }', ['id' => $projectId])['project'] ?? null;
                if (! $project) {
                    return ['ok' => false, 'error' => 'This token can\'t see the Railway project '.$projectId.'.'];
                }
                $workspaceId = $project['workspaceId'];
                $workspace = self::query($s['token'], 'query($id: String!) { workspace(workspaceId: $id) { id name plan projects { edges { node { id name } } } customer { currentUsage creditBalance billingPeriod { start end } invoices { invoiceId periodStart periodEnd total amountPaid amountDue status hostedURL } } } }', ['id' => $workspaceId])['workspace'] ?? null;
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Railway refused the request: '.$e->getMessage().'. Use a workspace or account token from railway.com/account/tokens.'];
            }
            if (! $workspace || empty($workspace['customer'])) {
                return ['ok' => false, 'error' => 'The workspace\'s billing isn\'t visible to this token.'];
            }
            $c = $workspace['customer'];
            $period = ['start' => CarbonImmutable::parse($c['billingPeriod']['start']), 'end' => CarbonImmutable::parse($c['billingPeriod']['end'])];
            $projects = collect($workspace['projects']['edges'] ?? [])->pluck('node')->pluck('name', 'id');

            $invoices = collect($c['invoices'] ?? [])
                ->reject(fn ($i) => in_array($i['status'] ?? '', ['void', 'draft', 'uncollectible'], true))
                ->map(fn ($i) => [
                    'id' => $i['invoiceId'] ?? null,
                    'date' => CarbonImmutable::parse($i['periodEnd'] ?: $i['periodStart'])->toDateString(),
                    'total' => round(((int) ($i['total'] ?? 0)) / 100, 2),
                    // What was actually charged, after credits.
                    'charged' => round(((int) (($i['status'] ?? '') === 'paid' ? ($i['amountPaid'] ?? 0) : ($i['amountDue'] ?? 0))) / 100, 2),
                    'status' => $i['status'] ?? '',
                    'url' => $i['hostedURL'] ?? null,
                ])
                ->sortByDesc('date')->values()->all();

            // This project's share of the workspace's usage: for each invoice, the month before it; and the running period.
            $windows = ['now' => [$period['start'], now()->toImmutable()->min($period['end'])]];
            foreach ($invoices as $k => $i) {
                $date = CarbonImmutable::parse($i['date']);
                $windows['i'.$k] = [$date->subMonth(), $date];
            }
            // The only project in the workspace: the whole bill is its cost.
            $shares = $projects->count() > 1 ? self::shares($s['token'], $workspaceId, $projectId, $windows) : null;
            foreach ($invoices as $k => $i) {
                $invoices[$k]['share'] = $shares === null ? 1.0 : ($shares['i'.$k] ?? null);
            }

            return [
                'ok' => true,
                'workspace' => (string) $workspace['name'],
                'plan' => (string) ($workspace['plan'] ?? ''),
                'project' => $project['name'],
                'project_id' => $projectId,
                'projects' => $projects->all(),
                'period' => ['start' => $period['start']->toDateString(), 'end' => $period['end']->toDateString()],
                'usage' => round((float) ($c['currentUsage'] ?? 0), 2),
                'credit' => round((float) ($c['creditBalance'] ?? 0), 2),
                'share_now' => $shares === null ? 1.0 : ($shares['now'] ?? null),
                'invoices' => $invoices,
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * The project's share (0–1) of the workspace's weighted usage in each window, in one request.
     *
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @return array<string, ?float>
     */
    private static function shares(string $token, string $workspaceId, string $projectId, array $windows): array
    {
        $parts = [];
        $vars = ['w' => $workspaceId, 'p' => $projectId, 'm' => array_keys(self::WEIGHTS)];
        $defs = ['$w: String!', '$p: String!', '$m: [MetricMeasurement!]!'];
        foreach ($windows as $key => [$from, $to]) {
            $defs[] = "\${$key}s: DateTime!";
            $defs[] = "\${$key}e: DateTime!";
            $vars[$key.'s'] = $from->toIso8601ZuluString();
            $vars[$key.'e'] = $to->toIso8601ZuluString();
            $parts[] = "w_{$key}: usage(workspaceId: \$w, measurements: \$m, startDate: \${$key}s, endDate: \${$key}e, includeDeleted: true) { measurement value }";
            $parts[] = "p_{$key}: usage(projectId: \$p, measurements: \$m, startDate: \${$key}s, endDate: \${$key}e, includeDeleted: true) { measurement value }";
        }
        try {
            $data = self::query($token, 'query('.implode(', ', $defs).') { '.implode(' ', $parts).' }', $vars);
        } catch (Throwable) {
            return array_fill_keys(array_keys($windows), null);
        }
        $weigh = fn ($rows) => array_sum(array_map(fn ($r) => (self::WEIGHTS[$r['measurement']] ?? 0) * (float) $r['value'], $rows ?? []));
        $out = [];
        foreach (array_keys($windows) as $key) {
            $all = $weigh($data['w_'.$key] ?? []);
            $out[$key] = $all > 0 ? round(min(1, $weigh($data['p_'.$key] ?? []) / $all), 4) : null;
        }

        return $out;
    }

    /**
     * Railway's cost for a month: invoices issued that month, plus an estimate for the running period
     * when its invoice is due that month and hasn't been issued yet; each is this project's share.
     *
     * @return array{connected: bool, project?: string, amount: float, invoices: list<array>, estimate: ?array, error?: string}
     */
    public static function forMonth(string $month): array
    {
        $data = self::data();
        if (! $data['ok']) {
            return ['connected' => false, 'amount' => 0.0, 'invoices' => [], 'estimate' => null, 'error' => $data['error']];
        }
        // Unknown share (no usage recorded in the period): nothing is counted for the project.
        $part = fn (float $amount, ?float $share) => round($amount * ($share ?? 0.0), 2);

        $invoices = array_values(array_map(
            fn ($i) => $i + ['counted' => $part($i['charged'], $i['share'])],
            array_filter($data['invoices'], fn ($i) => str_starts_with($i['date'], $month)),
        ));
        $estimate = null;
        if (str_starts_with($data['period']['end'], $month) && ! collect($data['invoices'])->contains(fn ($i) => $i['date'] >= $data['period']['end'])) {
            $min = self::PLAN_MINIMUM[$data['plan']] ?? 0.0;
            $bill = round(max(0, max($min, $data['usage']) - $data['credit']), 2);
            $estimate = ['bill' => $bill, 'amount' => $part($bill, $data['share_now']), 'share' => $data['share_now'], 'usage' => $data['usage'], 'minimum' => $min, 'due' => $data['period']['end']];
        }

        return [
            'connected' => true,
            'project' => $data['project'],
            'amount' => round(array_sum(array_column($invoices, 'counted')) + ($estimate['amount'] ?? 0), 2),
            'invoices' => $invoices,
            'estimate' => $estimate,
        ];
    }
}
