import { appUrl, route, useShared, withQuery, useUrl } from '../router.jsx';
import { ago, Badge, Banner, Button, money, number, percent, Section, Trend, Upgrade } from '../components/ui.jsx';
import { DailyChart, Flow, Pill, Ring } from '../components/charts.jsx';

const pct = (now, before) => (before ? ((now - before) / Math.abs(before)) * 100 : null);

/**
 * Home: the profit snapshot (Final MVP F2/F12), what's still to set up, and data sync status.
 * Every number explains itself; missing costs are shown, never hidden.
 */
export default function Home({ range, ranges, profit: p, previous, sync, counts, checklist }) {
  const shared = useShared();
  const url = useUrl();
  const c = p.currency;
  const syncing = sync.products.running || sync.orders.running;
  const neverSynced = sync.orders.status === 'never' && sync.products.status === 'never';
  const todo = checklist.filter((s) => !s.done);
  const label = rangeLabel(ranges, range);

  return (
    <div className="si-page">
      <header className="si-head">
        <div>
          <h1>{greeting()}{shared.user?.name ? `, ${shared.user.name}` : ''}</h1>
          <p>Here's what {shared.storeName || 'your store'} really earned in the {label}.</p>
        </div>
        {Object.keys(ranges).length > 1 && (
          <nav className="seg" aria-label="Date range">
            {Object.entries(ranges).map(([key, l]) => (
              <a key={key} aria-selected={range === key} href={appUrl(withQuery(url, { range: key }))}>{l.replace('Last ', '')}</a>
            ))}
          </nav>
        )}
      </header>

      {(syncing || neverSynced) && (
        <Banner tone="info" heading="Importing your products and orders">
          <p>This usually takes a few minutes. Numbers fill in as the import runs — refresh this page in a moment.</p>
        </Banner>
      )}
      {(sync.orders.status === 'failed' || sync.products.status === 'failed') && (
        <Banner tone="critical" heading="The last data sync didn't finish">
          <p>We'll retry automatically. You can also start it again from Settings.</p>
          <Button slot="secondary-actions" href={appUrl(route('app.settings.store'))}>Open data sync</Button>
        </Banner>
      )}
      {p.coverage.percent !== null && p.coverage.percent < 80 && (
        <Banner tone="warning" heading={`${percent(p.coverage.percent, 0)} of units sold have a product cost.`}>
          <p>Profit is overstated until the rest have costs{p.configured.default_margin ? ' (your default margin is used for them, as an assumption)' : ''} — start with your best sellers.</p>
          <Button slot="secondary-actions" href={appUrl(route('app.products.index'))}>{counts.missing_costs > 0 ? `Add ${number(counts.missing_costs)} missing costs` : 'Add missing costs'}</Button>
        </Banner>
      )}

      <section className="hero-profit" aria-label="Profit after ads">
        <div>
          <span className="label">Profit after ads · {label}</span>
          <span className={`big${p.profit_after_ads < 0 ? ' neg' : ''}`}>{money(p.profit_after_ads, c)}</span>
          <div className="meta">
            <Pill value={pct(p.profit_after_ads, previous.profit_after_ads)} />
            <span>{p.margin === null ? 'Margin —' : `Margin ${percent(p.margin)}`}</span>
          </div>
          <dl>
            <div><dt>Net sales</dt><dd>{money(p.net_sales, c)}</dd></div>
            <div><dt>Ad spend</dt><dd>{money(p.ad_spend, c)}</dd></div>
            <div><dt>Orders</dt><dd>{number(p.orders)}</dd></div>
            <div><dt>Avg. order</dt><dd>{money(p.aov, c)}</dd></div>
          </dl>
        </div>
        <DailyChart daily={p.daily} currency={c} />
      </section>

      <div className="si-grid-4">
        <div className="kpi" title="Net sales − product costs (cost at the time of each order).">
          <small>Gross profit</small>
          <b>{money(p.gross_profit, c)}</b>
          <span><Trend value={pct(p.gross_profit, previous.gross_profit)} /> Product costs {money(p.cogs, c)}</span>
        </div>
        <div className="kpi" title="Gross profit − payment fees − shipping − handling.">
          <small>Contribution profit</small>
          <b>{money(p.contribution_profit, c)}</b>
          <span>After fees {money(p.payment_fees, c)} · shipping {money(p.shipping_costs, c)}</span>
        </div>
        <div className="kpi" title="Refunded and returned amounts in this period.">
          <small>Returns</small>
          <b>{money(p.returns, c)}</b>
          <span>{p.gross_sales > 0 ? `${percent((p.returns / p.gross_sales) * 100)} of gross sales` : 'No sales yet'}</span>
        </div>
        <div className="kpi ring" title="Share of units sold that have a product cost. Below 100%, profit is overstated.">
          <Ring value={p.coverage.percent} />
          <div>
            <small className="muted">Cost coverage</small>
            <b className="num" style={{ fontSize: 26, fontWeight: 600 }}>{p.coverage.percent === null ? '—' : percent(p.coverage.percent, 0)}</b>
            <span className="muted small">{number(p.coverage.units_with_cost)} of {number(p.coverage.units)} units</span>
          </div>
        </div>
      </div>

      <div className="si-grid-2">
        <Section heading="Where your money went" action={!p.configured.payment_fees && <a href={appUrl(route('app.settings.costs'))} className="small">Add fees &amp; shipping</a>}>
          <Flow p={p} />
          {!p.configured.payment_fees && <p className="muted small">Payment fees aren't set up yet, so they count as $0.</p>}
        </Section>

        <div className="si-col" style={{ gap: 16 }}>
          {todo.length > 0 && (
            <Section heading="Finish setting up" action={<span className="muted small">{checklist.length - todo.length} of {checklist.length} done</span>}>
              <div className="setup-progress"><i style={{ width: `${((checklist.length - todo.length) / checklist.length) * 100}%` }} /></div>
              <ul className="checklist">
                {checklist.map((s) => (
                  <li key={s.key} className={s.done ? 'done' : ''}>
                    <span className="tick" aria-hidden="true">{s.done ? '✓' : ''}</span>
                    <div><a href={appUrl(s.href)}>{s.label}</a><small>{s.help}</small></div>
                  </li>
                ))}
              </ul>
            </Section>
          )}
          <Section heading="Your data" action={<a className="small" href={appUrl(route('app.settings.store'))}>Data sync</a>}>
            <dl className="oo-kv">
              <dt>Products</dt><dd>{number(counts.products)} <span className="muted">({number(counts.variants)} variants)</span></dd>
              <dt>Missing costs</dt><dd>{counts.missing_costs > 0 ? <a href={appUrl(route('app.products.index'))}>{number(counts.missing_costs)} variants</a> : 'None'}</dd>
              <dt>Cost rules</dt><dd>{counts.cost_rules > 0 ? `${counts.cost_rules} active` : <a href={appUrl(route('app.settings.costs'))}>Add fees &amp; shipping</a>}</dd>
              <dt>Products synced</dt><dd>{syncLabel(sync.products)}</dd>
              <dt>Orders synced</dt><dd>{syncLabel(sync.orders)}</dd>
            </dl>
            {counts.missing_costs > 0 && (
              <a className="next-step" href={appUrl(route('app.products.index'))}><span>Add costs to your best sellers</span><b>Accurate profit →</b></a>
            )}
          </Section>
        </div>
      </div>

      {!shared.includes?.includes('profit_dashboard') && (
        <Upgrade feature="profit_dashboard" heading="See any date range and full history">Your plan shows the last 30 days.</Upgrade>
      )}
    </div>
  );
}

function rangeLabel(ranges, range) {
  return (ranges[range] || 'last 30 days').toLowerCase();
}

function syncLabel(s) {
  if (s.running) return <Badge tone="info" dot>Syncing…</Badge>;
  if (s.status === 'failed') return <Badge tone="critical">Failed — retrying</Badge>;
  if (!s.last_success_at) return <span className="muted">Not yet</span>;
  return <>Up to date <span className="muted">· {ago(s.last_success_at)}</span></>;
}

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}
