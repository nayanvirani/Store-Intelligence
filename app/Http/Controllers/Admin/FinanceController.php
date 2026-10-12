<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FinanceEntry;
use App\Support\Finance;
use App\Support\RailwayBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Finance: monthly earnings, expenses (fixed costs, percentage fees, manual entries) and profit.
 */
class FinanceController extends Controller
{
    public function show(Request $request): View
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');

        return view('admin.finance', [
            'month' => Finance::month($month),
            'history' => Finance::months(12),
            'settings' => Finance::settings(),
            'railway' => RailwayBilling::data(),
            'railwaySettings' => RailwayBilling::settings(),
            'categories' => Finance::CATEGORIES,
            'currency' => config('shopify.billing.currency', 'USD'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:expense,income'],
            'date' => ['required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'description' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
        ]);
        $entry = FinanceEntry::create($data + ['created_by' => $request->user()->id]);
        AuditLog::record('admin.finance_entry_added', null, ['by' => $request->user()->email, 'kind' => $entry->kind, 'amount' => $entry->amount]);

        return redirect()->route('admin.finance', ['month' => $entry->date->format('Y-m')])->with('status', ucfirst($entry->kind).' added.');
    }

    public function update(Request $request, FinanceEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:expense,income'],
            'date' => ['required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'description' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
        ]);
        $entry->update($data);

        return redirect()->route('admin.finance', ['month' => $entry->date->format('Y-m')])->with('status', 'Entry updated.');
    }

    public function destroy(Request $request, FinanceEntry $entry): RedirectResponse
    {
        $month = $entry->date->format('Y-m');
        AuditLog::record('admin.finance_entry_deleted', null, ['by' => $request->user()->email, 'description' => $entry->description, 'amount' => $entry->amount]);
        $entry->delete();

        return redirect()->route('admin.finance', ['month' => $month])->with('status', 'Entry deleted.');
    }

    public function settings(Request $request): RedirectResponse
    {
        Finance::save((array) $request->input('recurring', []), (array) $request->input('fees', []));
        AuditLog::record('admin.finance_settings_saved', null, ['by' => $request->user()->email]);

        return back()->with('status', 'Monthly costs and fees saved.');
    }

    /** Railway API token (encrypted) and the project to count, or disconnect. */
    public function railway(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['nullable', 'string', 'max:200'], 'project_id' => ['nullable', 'string', 'max:64']]);
        $forget = $request->boolean('disconnect');
        RailwayBilling::save($request->input('token'), $request->input('project_id'), $forget);
        AuditLog::record($forget ? 'admin.railway_disconnected' : 'admin.railway_connected', null, ['by' => $request->user()->email]);
        if ($forget) {
            return back()->with('status', 'Railway disconnected.');
        }
        $data = RailwayBilling::data();

        return back()->with('status', $data['ok'] ? 'Railway connected: costs of the '.$data['project'].' project.' : 'Saved, but '.lcfirst($data['error']));
    }

    public function railwayRefresh(): RedirectResponse
    {
        RailwayBilling::refresh();

        return back()->with('status', 'Railway costs refreshed.');
    }
}
