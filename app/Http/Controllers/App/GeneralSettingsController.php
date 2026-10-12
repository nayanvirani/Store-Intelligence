<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → General (Final MVP F3): reporting, default margin assumption and email preferences.
 */
class GeneralSettingsController extends Controller
{
    public const RANGES = ['last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days', 'month_to_date' => 'Month to date', 'last_90_days' => 'Last 90 days'];

    public function show(Store $store): Page
    {
        return page('settings/general', [
            'settings' => $store->allSettings(),
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'currencies' => array_values(array_unique(array_filter(array_merge([$store->currency], (array) $store->capability('presentment_currencies'))))),
            'ranges' => self::RANGES,
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'default_range' => ['required', 'in:'.implode(',', array_keys(self::RANGES))],
            'week_starts_on' => ['required', 'in:monday,sunday'],
            'exclude_test_orders' => ['boolean'],
            'default_margin' => ['nullable', 'numeric', 'min:0', 'max:95'],
            'digest' => ['required', 'in:daily,weekly,off'],
            'digest_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'alert_emails' => ['nullable', 'string', 'max:1000'],
        ], ['alert_emails.max' => 'Too many email addresses.']);

        $emails = collect(preg_split('/[\s,;]+/', (string) ($data['alert_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => strtolower(trim($e)))->unique()->values();
        if ($bad = $emails->first(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['alert_emails' => "“{$bad}” isn't a valid email address."]);
        }

        $settings = array_merge($store->settings ?? [], [
            'default_range' => $data['default_range'],
            'week_starts_on' => $data['week_starts_on'],
            'exclude_test_orders' => $request->boolean('exclude_test_orders'),
            'default_margin' => isset($data['default_margin']) && $data['default_margin'] !== '' ? round((float) $data['default_margin'], 1) : null,
            'digest' => $data['digest'],
            'digest_hour' => (int) $data['digest_hour'],
            'alert_emails' => $emails->take(10)->all(),
        ]);
        $store->forceFill(['settings' => $settings])->save();
        AuditLog::record('settings.updated', $store, array_intersect_key($settings, array_flip(['default_range', 'exclude_test_orders', 'default_margin', 'digest'])));

        return redirect()->to(app_route('app.settings.general', ['notice' => 'saved']));
    }
}
