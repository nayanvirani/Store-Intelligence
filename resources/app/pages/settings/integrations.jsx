import { ago, Page } from '../../components/ui.jsx';

/** Settings → Integrations (Final MVP F6): Shopify, connected accounts and webhooks. */
export default function Integrations({ shopify, providers, webhooks }) {
  const groups = [...new Set(providers.map((p) => p.group))];

  return (
    <Page heading="Integrations">
      <s-section heading="Shopify">
        <div className="rule-row">
          <div><strong>Shopify</strong><div className="muted small">{shopify.shop} · read-only access to products, inventory and orders</div></div>
          <s-badge tone={shopify.connected ? 'success' : 'critical'}>{shopify.connected ? 'Connected' : 'Needs attention'}</s-badge>
        </div>
      </s-section>

      {groups.map((group) => (
        <s-section key={group} heading={group}>
          {providers.filter((p) => p.group === group).map((p) => (
            <div className="rule-row" key={p.key}>
              <div><strong>{p.name}</strong><div className="muted small">{p.help}</div></div>
              {p.available ? <s-button disabled>Connect</s-button> : <s-badge>Coming soon</s-badge>}
            </div>
          ))}
        </s-section>
      ))}

      <s-section heading="Shopify webhooks">
        <p className="muted">Shopify tells Store Intelligence about these changes. Each is verified with Shopify's signature and handled once.</p>
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Event</th><th className="num">Received · 30 days</th><th>Last received</th></tr></thead>
            <tbody>
              {webhooks.map((w) => (
                <tr key={w.topic}>
                  <td>{w.label} <span className="oo-code">{w.topic}</span></td>
                  <td className="num">{w.received}{w.unprocessed > 0 && <s-badge tone="warning">{w.unprocessed} pending</s-badge>}</td>
                  <td>{w.last_at ? ago(w.last_at) : <span className="muted">Not yet</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </s-section>
    </Page>
  );
}
