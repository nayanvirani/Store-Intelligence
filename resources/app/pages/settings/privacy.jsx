import { Check, Form, useForm } from '../../components/form.jsx';
import { date, number, Page } from '../../components/ui.jsx';
import { appUrl, route, useShared } from '../../router.jsx';

/** Settings → Privacy: AI processing, data held, export and policies. */
export default function Privacy({ privacy, data, policies }) {
  const { can } = useShared();
  const form = useForm({ ai_enabled: !!privacy.ai_enabled });

  return (
    <Page heading="Privacy">
      <s-section heading="What Store Intelligence keeps">
        <s-paragraph>Store details, products, costs, inventory and orders ({number(data.orders)} orders, {number(data.products)} products). From orders we keep amounts, line items, refunds, sales channel and traffic source, and the Shopify customer ID — never customers' names, emails, addresses or payment details.</s-paragraph>
        <s-paragraph>When you uninstall, Shopify asks apps to delete store data 48 hours later; Store Intelligence deletes it then.</s-paragraph>
        {can.manage_settings && (
          <s-stack direction="inline" gap="small-200" paddingBlockStart="base">
            <s-button href={appUrl(route('app.settings.privacy.export'))} target="_blank">Export orders (CSV)</s-button>
          </s-stack>
        )}
      </s-section>

      <s-section heading="AI features">
        <Form onSubmit={() => form.post(route('app.settings.privacy.update'))}>
          <Check label="Allow AI features" help="AI writes captions, plans and summaries from your store metrics and product names. Customer personal data is never sent to AI." {...form.bind('ai_enabled', { type: 'checkbox' })} />
          {can.manage_settings && <s-button type="submit" loading={form.processing || undefined}>Save</s-button>}
        </Form>
      </s-section>

      <s-section heading="Policies">
        <ul className="plain">
          {policies.map((p) => (
            <li key={p.url}><a href={p.url} target="_blank" rel="noopener noreferrer">{p.title}</a> <span className="muted small">· version {p.version}{p.accepted ? ` · reviewed ${date(p.accepted.at || p.accepted)}` : ''}</span></li>
          ))}
        </ul>
      </s-section>
    </Page>
  );
}
