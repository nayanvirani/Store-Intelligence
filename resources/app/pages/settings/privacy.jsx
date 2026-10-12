import { Check, Form, useForm } from '../../components/form.jsx';
import { Button, date, number, Page, Section, Stack } from '../../components/ui.jsx';
import { appUrl, route, useShared } from '../../router.jsx';

/** Settings → Privacy: AI processing, data held, export and policies. */
export default function Privacy({ privacy, data, policies }) {
  const { can } = useShared();
  const form = useForm({ ai_enabled: !!privacy.ai_enabled });

  return (
    <Page heading="Privacy" subtitle="What Store Intelligence stores, how AI is used, and your data export.">
      <Section heading="What Store Intelligence keeps">
        <p>Store details, products, costs, inventory and orders ({number(data.orders)} orders, {number(data.products)} products). From orders we keep amounts, line items, refunds, sales channel and traffic source, and the Shopify customer ID — never customers' names, emails, addresses or payment details.</p>
        <p>When you uninstall, Shopify asks apps to delete store data 48 hours later; Store Intelligence deletes it then.</p>
        {can.manage_settings && (
          <Stack direction="inline" paddingBlockStart="base">
            <Button href={appUrl(route('app.settings.privacy.export'))} target="_blank">Export orders (CSV)</Button>
          </Stack>
        )}
      </Section>

      <Section heading="AI features">
        <Form onSubmit={() => form.post(route('app.settings.privacy.update'))}>
          <Check label="Allow AI features" help="AI writes captions, plans and summaries from your store metrics and product names. Customer personal data is never sent to AI." {...form.bind('ai_enabled', { type: 'checkbox' })} />
          {can.manage_settings && <Button type="submit" loading={form.processing}>Save</Button>}
        </Form>
      </Section>

      <Section heading="Policies">
        <ul className="plain">
          {policies.map((p) => (
            <li key={p.url}><a href={p.url} target="_blank" rel="noopener noreferrer">{p.title}</a> <span className="muted small">· version {p.version}{p.accepted ? ` · reviewed ${date(p.accepted.at || p.accepted)}` : ''}</span></li>
          ))}
        </ul>
      </Section>
    </Page>
  );
}
