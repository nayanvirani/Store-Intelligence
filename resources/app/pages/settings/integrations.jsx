import { ago, Badge, Button, Icon, Page, Section } from '../../components/ui.jsx';

/** Settings → Integrations (Final MVP F6): Shopify, connected accounts and webhooks. */
export default function Integrations({ shopify, providers, webhooks }) {
  const groups = [...new Set(providers.map((p) => p.group))];

  return (
    <Page heading="Integrations" subtitle="Shopify is connected on install. Ad, analytics and social accounts connect here as each feature launches.">
      <Section heading="Shopify">
        <div className="rule-row">
          <Icon name="bag" />
          <div><strong>Shopify</strong><div className="muted small">{shopify.shop} · read-only access to products, inventory and orders</div></div>
          <Badge tone={shopify.connected ? 'success' : 'critical'}>{shopify.connected ? 'Connected' : 'Needs attention'}</Badge>
        </div>
      </Section>

      {groups.map((group) => (
        <Section key={group} heading={group}>
          {providers.filter((p) => p.group === group).map((p) => (
            <div className="rule-row" key={p.key}>
              <Icon name={p.icon || 'plug'} />
              <div><strong>{p.name}</strong><div className="muted small">{p.help}</div></div>
              {p.available ? <Button disabled>Connect</Button> : <Badge>Coming soon</Badge>}
            </div>
          ))}
        </Section>
      ))}

      <Section heading="Shopify webhooks">
        <p className="muted" style={{ margin: 0 }}>Shopify tells Store Intelligence about these changes. Each is verified with Shopify's signature and handled once.</p>
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Event</th><th className="num">Received · 30 days</th><th>Last received</th></tr></thead>
            <tbody>
              {webhooks.map((w) => (
                <tr key={w.topic}>
                  <td>{w.label} <span className="oo-code">{w.topic}</span></td>
                  <td className="num">{w.received}{w.unprocessed > 0 && <Badge tone="warning">{w.unprocessed} pending</Badge>}</td>
                  <td>{w.last_at ? ago(w.last_at) : <span className="muted">Not yet</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>
    </Page>
  );
}
