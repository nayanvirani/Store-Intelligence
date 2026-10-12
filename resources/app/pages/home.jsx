import { appUrl, route, useShared, withQuery, useUrl } from '../router.jsx';
import { ago, money, number, percent, Upgrade } from '../components/ui.jsx';

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

  return (
    <s-page inlineSize="large" heading="Home">
      <div className="dash">
        <header className="dash-head">
          <div>
            <h1>{greeting()}{shared.user?.name ? `, ${shared.user.name}` : ''}</h1>
            <p>Here's what {shared.storeName || 'your store'} really earned — {rangeLabel(ranges, range)}.</p>
          </div>
          {Object.keys(ranges).length > 1 && (
            <div className="dash-range" role="tablist" aria-label="Date range">
              {Object.entries(ranges).map(([key, label]) => (
                <a key={key} role="tab" aria-selected={range === key} className={range === key ? 'on' : ''} href={appUrl(withQuery(url, { range: key }))}>{label.replace('Last ', '')}</a>
              ))}
            </div>
          )}
        </header>

        {(syncing || neverSynced) && (
          <s-banner tone="info" heading="Importing your products and orders">
            <s-paragraph>This usually takes a few minutes. Numbers fill in as the import runs — refresh this page in a moment.</s-paragraph>
          </s-banner>
        )}
        {(sync.orders.status === 'failed' || sync.products.status === 'failed') && (
          <s-banner tone="critical" heading="The last data sync didn't finish">
            <s-paragraph>We'll retry automatically. You can also start it again from Settings.</s-paragraph>
            <s-button slot="secondary-actions" href={appUrl(route('app.settings.store'))}>Open data sync</s-button>
          </s-banner>
        )}
        {p.coverage.percent !== null && p.coverage.percent < 80 && (
          <s-banner tone="warning" heading={`Only ${percent(p.coverage.percent, 0)} of units sold have a product cost`}>
            <s-paragraph>Profit below doesn't include the cost of the rest{p.configured.default_margin ? ' — your default margin is used for them (an assumption)' : ''}. Add costs for your best sellers to make it accurate.</s-paragraph>
            <s-button slot="secondary-actions" href={appUrl(route('app.products.index'))}>Add missing costs</s-button>
          </s-banner>
        )}

        <div className="kpis">
          <Kpi label="Net sales" value={money(p.net_sales, c)} trend={pct(p.net_sales, previous.net_sales)} sub={`${number(p.orders)} orders · AOV ${money(p.aov, c)}`} help="Gross sales − discounts − returns. Taxes are not included." />
          <Kpi label="Profit after ads" value={money(p.profit_after_ads, c)} trend={pct(p.profit_after_ads, previous.profit_after_ads)} tone={p.profit_after_ads < 0 ? 'bad' : 'good'} sub={p.margin === null ? 'Margin —' : `Margin ${percent(p.margin)}`} help="Net sales − product costs − payment fees − shipping − handling − ad spend." />
          <Kpi label="Gross profit" value={money(p.gross_profit, c)} sub={`Product costs ${money(p.cogs, c)}`} help="Net sales − product costs (cost at the time of each order)." />
          <Kpi label="Cost coverage" value={p.coverage.percent === null ? '—' : percent(p.coverage.percent, 0)} sub={`${number(p.coverage.units_with_cost)} of ${number(p.coverage.units)} units have a cost`} help="Share of units sold that have a product cost. Below 100%, profit is overstated." />
        </div>

        <div className="dash-two">
          <section className="card">
            <h2 className="card-title">Profit breakdown</h2>
            <Waterfall p={p} />
            {!p.configured.payment_fees && (
              <p className="muted small" style={{ marginTop: 10 }}>
                Payment fees aren't set up yet, so they count as $0. <a href={appUrl(route('app.settings.costs'))}>Add fees and shipping</a>
              </p>
            )}
          </section>
          <section className="card">
            <h2 className="card-title">Daily net sales and profit</h2>
            <DailyChart daily={p.daily} currency={c} />
            {!shared.includes?.includes('profit_dashboard') && (
              <div style={{ marginTop: 12 }}><Upgrade feature="profit_dashboard" heading="See any date range and full history">Your plan shows the last 30 days.</Upgrade></div>
            )}
          </section>
        </div>

        <div className="dash-two">
          {todo.length > 0 && (
            <section className="card">
              <h2 className="card-title">Finish setting up</h2>
              <div className="setup-progress"><i style={{ width: `${((checklist.length - todo.length) / checklist.length) * 100}%` }} /></div>
              <p className="muted small" style={{ margin: '6px 0 10px' }}>{checklist.length - todo.length} of {checklist.length} done</p>
              <ul className="checklist">
                {checklist.map((s) => (
                  <li key={s.key} className={s.done ? 'done' : ''}>
                    <span className="tick" aria-hidden="true">{s.done ? '✓' : ''}</span>
                    <div><a href={appUrl(s.href)}>{s.label}</a><small>{s.help}</small></div>
                  </li>
                ))}
              </ul>
            </section>
          )}
          <section className="card">
            <h2 className="card-title">Your data</h2>
            <dl className="oo-kv">
              <dt>Products</dt><dd>{number(counts.products)} <span className="muted">({number(counts.variants)} variants)</span></dd>
              <dt>Missing costs</dt><dd>{counts.missing_costs > 0 ? <a href={appUrl(route('app.products.index'))}>{number(counts.missing_costs)} variants</a> : 'None'}</dd>
              <dt>Cost rules</dt><dd>{counts.cost_rules > 0 ? `${counts.cost_rules} active` : <a href={appUrl(route('app.settings.costs'))}>Add fees & shipping</a>}</dd>
              <dt>Products synced</dt><dd>{syncLabel(sync.products)}</dd>
              <dt>Orders synced</dt><dd>{syncLabel(sync.orders)}</dd>
            </dl>
          </section>
        </div>
      </div>
    </s-page>
  );
}

function rangeLabel(ranges, range) {
  return (ranges[range] || '').toLowerCase();
}

function syncLabel(s) {
  if (s.running) return <s-badge tone="info">Syncing…</s-badge>;
  if (s.status === 'failed') return <s-badge tone="critical">Failed — retrying</s-badge>;
  if (!s.last_success_at) return <span className="muted">Not yet</span>;
  return <>Up to date <span className="muted">· {ago(s.last_success_at)}</span></>;
}

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}

function Kpi({ label, value, sub, trend, tone, help }) {
  const dir = trend > 0.5 ? 'up' : trend < -0.5 ? 'down' : 'flat';
  return (
    <div className={`kpi${tone ? ` kpi-${tone}` : ''}`} title={help}>
      <small>{label}</small>
      <b>
        {value}
        {trend !== null && trend !== undefined && Number.isFinite(trend) && <span className={`ui-trend ${dir}`}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(trend))}%</span>}
      </b>
      {sub && <span>{sub}</span>}
    </div>
  );
}

function Waterfall({ p }) {
  const c = p.currency;
  const rows = [
    ['Gross sales', p.gross_sales, 'base'],
    ['Discounts', -p.discounts],
    ['Returns', -p.returns],
    ['Net sales', p.net_sales, 'sub'],
    ['Product costs', -p.cogs, null, p.assumed_cogs > 0 ? `includes ${money(p.assumed_cogs, c)} assumed` : null],
    ['Gross profit', p.gross_profit, 'sub'],
    ['Payment fees', -p.payment_fees],
    ['Shipping', -p.shipping_costs],
    ['Handling', -p.handling_costs],
    ['Contribution profit', p.contribution_profit, 'sub'],
    ['Ad spend', -p.ad_spend],
    ['Profit after ads', p.profit_after_ads, 'total'],
  ];
  if (p.net_profit !== null) {
    rows.push(['Fixed costs', -p.fixed_costs], ['Estimated net profit', p.net_profit, 'total']);
  }
  return (
    <table className="waterfall">
      <tbody>
        {rows.map(([label, value, kind, note]) => (
          <tr key={label} className={kind || ''}>
            <td>{label}{note && <small className="muted"> · {note}</small>}</td>
            <td className={value < 0 && kind ? 'neg' : ''}>{kind ? money(value, c) : value === 0 ? <span className="muted">{money(0, c)}</span> : `− ${money(Math.abs(value), c)}`}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function DailyChart({ daily, currency }) {
  const max = Math.max(1, ...daily.map((d) => Math.max(d.net_sales, d.profit, 0)));
  const min = Math.min(0, ...daily.map((d) => d.profit));
  const span = max - min || 1;
  const H = 160;
  const y = (v) => H - ((v - min) / span) * H;
  const w = 100 / Math.max(1, daily.length);
  if (!daily.some((d) => d.orders > 0 || d.net_sales !== 0)) {
    return <p className="muted">No orders in this period yet.</p>;
  }
  return (
    <div>
      <svg viewBox={`0 0 100 ${H}`} preserveAspectRatio="none" className="daily-chart" role="img" aria-label="Daily net sales and profit">
        <line x1="0" x2="100" y1={y(0)} y2={y(0)} className="zero" />
        {daily.map((d, i) => (
          <g key={d.date}>
            <title>{`${d.date}: net sales ${money(d.net_sales, currency)}, profit ${money(d.profit, currency)}`}</title>
            <rect x={i * w + w * 0.15} width={w * 0.7} y={Math.min(y(d.net_sales), y(0))} height={Math.abs(y(0) - y(d.net_sales))} className="bar" />
          </g>
        ))}
        <polyline points={daily.map((d, i) => `${(i + 0.5) * w},${y(d.profit)}`).join(' ')} className="line" />
      </svg>
      <div className="legend"><span><i className="sw bar" /> Net sales</span><span><i className="sw line" /> Profit</span></div>
    </div>
  );
}
