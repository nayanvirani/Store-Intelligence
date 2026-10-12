import { ActionButton } from '../../components/form.jsx';
import { ago, date, KeyValue, number, Page } from '../../components/ui.jsx';
import { route, useShared } from '../../router.jsx';

const SYNC_LABELS = { products: 'Products, costs & stock', orders: 'Orders & refunds' };

/** Settings → Store & data sync (Final MVP F1, F7). */
export default function StoreSettings({ store, missingScopes, grantedScopes, sync, counts, history }) {
  const { can } = useShared();

  return (
    <Page heading="Store & data sync">
      {missingScopes.length > 0 && (
        <s-banner tone="critical" heading="Your Shopify connection needs attention">
          <s-paragraph>Store Intelligence is missing permissions it needs: {missingScopes.join(', ')}. Reconnect to approve them.</s-paragraph>
        </s-banner>
      )}

      <s-section heading="Data sync">
        <s-paragraph>Store Intelligence keeps a read-only copy of your products and orders. Webhooks update it within minutes; a full check runs every hour (orders) and every night (products).</s-paragraph>
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Data</th><th>Status</th><th>Last success</th><th className="num">Last run</th></tr></thead>
            <tbody>
              {Object.entries(SYNC_LABELS).map(([kind, label]) => {
                const s = sync[kind];
                return (
                  <tr key={kind}>
                    <td>{label}</td>
                    <td>{s.running ? <s-badge tone="info">Syncing…</s-badge> : s.status === 'failed' ? <s-badge tone="critical">Failed</s-badge> : s.status === 'done' ? <s-badge tone="success">Up to date</s-badge> : <s-badge>Not run yet</s-badge>}
                      {s.error && <div className="muted small">{s.error}</div>}
                    </td>
                    <td>{s.last_success_at ? ago(s.last_success_at) : '—'}</td>
                    <td className="num">{s.records === null || s.records === undefined ? '—' : `${number(s.records)} records`}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <KeyValue rows={[
          ['Products', `${number(counts.products)} (${number(counts.variants)} variants)`],
          ['Orders', number(counts.orders)],
          ['Oldest order', counts.oldest_order ? date(counts.oldest_order) : '—'],
          ['Order history', history.all_orders ? 'All orders (your plan decides how far back reports go)' : 'Last 60 days (Shopify\'s limit until full history is approved)'],
          ['Traffic source on orders', history.visit_source ? 'Included' : 'Not available on this store'],
        ]} />
        {can.manage_settings && (
          <s-stack direction="inline" gap="small-200" paddingBlockStart="base">
            <ActionButton url={route('app.settings.store.sync')}>Sync now</ActionButton>
          </s-stack>
        )}
      </s-section>

      <s-section heading="Shopify connection">
        <KeyValue rows={[
          ['Status', store.installed && !missingScopes.length ? <s-badge tone="success">Connected</s-badge> : <s-badge tone="critical">Needs attention</s-badge>],
          ['Store', <>{store.name || '—'} <span className="oo-muted">· {store.shop_domain}</span></>],
          ['Shopify plan', store.shopify_plan],
          ['Currency', store.currency],
          ['Timezone', store.timezone],
          ['Installed', date(store.installed_at)],
          ['Access', 'Read-only: products, inventory, locations and orders. Store Intelligence never changes your store.'],
          ['Granted permissions', <span className="oo-inline">{grantedScopes.length ? grantedScopes.map((s) => <span key={s} className="oo-code">{s}</span>) : '—'}</span>],
        ]} />
        {can.manage_settings && (
          <s-stack direction="inline" gap="small-200" alignItems="center" paddingBlockStart="base">
            <ActionButton url={route('app.settings.store.recheck')}>Refresh store details</ActionButton>
            <ActionButton variant="tertiary" url={route('app.settings.store.reconnect')}>Reconnect</ActionButton>
            <span className="oo-muted oo-small">Checked {ago(store.checked_at)}</span>
          </s-stack>
        )}
      </s-section>
    </Page>
  );
}
