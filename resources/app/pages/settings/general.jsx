import { Check, Field, Form, useForm } from '../../components/form.jsx';
import { Button, Page, Section, Stack } from '../../components/ui.jsx';
import { route, useShared } from '../../router.jsx';

/** Settings → General (Final MVP F3): reporting, default margin and email updates. */
export default function General({ settings, currency, timezone, ranges }) {
  const { can } = useShared();
  const form = useForm({
    default_range: settings.default_range,
    week_starts_on: settings.week_starts_on,
    exclude_test_orders: !!settings.exclude_test_orders,
    default_margin: settings.default_margin ?? '',
    digest: settings.digest,
    digest_hour: settings.digest_hour,
    alert_emails: (settings.alert_emails || []).join(', '),
  });
  const hours = Array.from({ length: 24 }, (_, h) => [h, new Date(2000, 0, 1, h).toLocaleTimeString('en-US', { hour: 'numeric' })]);

  return (
    <Page heading="General" subtitle="How reports are calculated and who gets email updates.">
      <Form onSubmit={() => form.post(route('app.settings.general.update'))}>
        <Section heading="Reporting">
          <div className="form-grid">
            <Field label="Currency" help="Amounts are shown in your store currency.">
              <input value={currency || ''} disabled />
            </Field>
            <Field label="Timezone" help="From your Shopify settings. Days start at midnight here.">
              <input value={timezone || ''} disabled />
            </Field>
            <Field label="Default date range" error={form.errors.default_range}>
              <select {...form.bind('default_range')}>{Object.entries(ranges).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
            </Field>
            <Field label="Weeks start on">
              <select {...form.bind('week_starts_on')}><option value="monday">Monday</option><option value="sunday">Sunday</option></select>
            </Field>
          </div>
          <Check label="Exclude test orders" help="Orders placed with Shopify's test gateway don't count in reports." {...form.bind('exclude_test_orders', { type: 'checkbox' })} />
        </Section>

        <Section heading="Products without a cost" description="Profit is only as accurate as your product costs.">
          <Field label="Default margin assumption (%)" error={form.errors.default_margin}
            help="Optional. Used only for products with no cost, and always labelled as an assumption. Leave empty to count no cost for them (profit will be overstated).">
            <input type="number" min="0" max="95" step="0.1" placeholder="e.g. 60" {...form.bind('default_margin')} />
          </Field>
        </Section>

        <Section heading="Email updates">
          <div className="form-grid">
            <Field label="Summary email">
              <select {...form.bind('digest')}><option value="daily">Daily</option><option value="weekly">Weekly (Mondays)</option><option value="off">Off</option></select>
            </Field>
            <Field label="Send at">
              <select {...form.bind('digest_hour')}>{hours.map(([h, l]) => <option key={h} value={h}>{l}</option>)}</select>
            </Field>
          </div>
          <Field label="Alert recipients" error={form.errors.alert_emails} help="Comma-separated. Empty: the store owner's email.">
            <input placeholder="you@store.com, ops@store.com" {...form.bind('alert_emails')} />
          </Field>
        </Section>

        {can.manage_settings && (
          <Stack direction="inline" paddingBlockStart="base">
            <Button variant="primary" type="submit" loading={form.processing}>Save</Button>
          </Stack>
        )}
      </Form>
    </Page>
  );
}
