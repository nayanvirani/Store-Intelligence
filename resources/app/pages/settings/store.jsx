import { ActionButton } from '../../components/form.jsx';
import { ago, Badge, Banner, date, KeyValue, number, Page, Section, Stack } from '../../components/ui.jsx';
import { route, useShared } from '../../router.jsx';

const SYNC_LABELS = { products: 'Products, costs & stock', orders: 'Orders & refunds' };

/** Settings → Store & data sync (Final MVP F1, F7). */
export default function StoreSettings({ store, missingScopes, grantedScopes, sync, counts, history }) {
  const { can } = useShared();

  return (
    <Page heading="Store & data sync" subtitle="Store Intelligence keeps a read-only copy of your products and orders. It never changes your store.">
      {missingScopes.length > 0 && (
        <Banner tone="critical" heading="Your Shopify connection needs attention">
          <p>Store Intelligence is missing permissions it needs: {missingScopes.join(', ')}. Reconnect to approve them.</p>
        </Banner>
      )}

      <Section heading="Data sync" description="Webhooks update your data within minutes; a full check runs every hour (orders) and every night (products).">
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Data</th><th>Status</th><th>Last success</th><th className="num">Last run</th></tr></thead>
            <tbody>
              {Object.entries(SYNC_LABELS).map(([kind, label]) => {
                const s = sync[kind];
                return (
                  <tr key={kind}>
                    <td>{label}</td>
                    <td>{s.running ? <Badge tone="info">Syncing…</Badge> : s.status === 'failed' ? <Badge tone="critical">Failed</Badge> : s.status === 'done' ? <Badge tone="success">Up to date</Badge> : <Badge>Not run yet</Badge>}
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
          <Stack direction="inline" paddingBlockStart="base">
            <ActionButton url={route('app.settings.store.sync')}>Sync now</ActionButton>
          </Stack>
        )}
      </Section>

      <Section heading="Shopify connection">
        <KeyValue rows={[
          ['Status', store.installed && !missingScopes.length ? <Badge tone="success">Connected</Badge> : <Badge tone="critical">Needs attention</Badge>],
          ['Store', <>{store.name || '—'} <span className="oo-muted">· {store.shop_domain}</span></>],
          ['Shopify plan', store.shopify_plan],
          ['Currency', store.currency],
          ['Timezone', store.timezone],
          ['Installed', date(store.installed_at)],
          ['Access', 'Read-only: products, inventory, locations and orders. Store Intelligence never changes your store.'],
          ['Granted permissions', <span className="oo-inline">{grantedScopes.length ? grantedScopes.map((s) => <span key={s} className="oo-code">{s}</span>) : '—'}</span>],
        ]} />
        {can.manage_settings && (
          <Stack direction="inline" alignItems="center" paddingBlockStart="base">
            <ActionButton url={route('app.settings.store.recheck')}>Refresh store details</ActionButton>
            <ActionButton variant="tertiary" url={route('app.settings.store.reconnect')}>Reconnect</ActionButton>
            <span className="oo-muted oo-small">Checked {ago(store.checked_at)}</span>
          </Stack>
        )}
      </Section>
    </Page>
  );
}
