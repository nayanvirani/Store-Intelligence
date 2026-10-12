import { appUrl, route, useUrl, withQuery } from '../../router.jsx';
import { Badge, Button, date, Empty, money, number, Page, Pagination, percent, Section, Upgrade } from '../../components/ui.jsx';
import { DailyChart, Pill } from '../../components/charts.jsx';
import { Delta, PeriodPicker, Term } from '../../components/period.jsx';

/** Formula behind each figure (section 7 metric definitions). */
const HELP = {
  gross_sales: 'Product price × quantity, before discounts. Taxes and shipping charged are not revenue.',
  discounts: 'Discount codes and automatic discounts on these orders.',
  returns: 'Refunds issued in this period, whenever the order was placed.',
  net_sales: 'Gross sales − discounts − returns.',
  cogs: 'Units kept × the product cost valid when each order was placed.',
  gross_profit: 'Net sales − product costs.',
  payment_fees: 'Your payment fee rules: % of order total + fixed amount per order.',
  shipping_costs: 'Your shipping cost rules: per order or per item.',
  handling_costs: 'Your handling rules, per order.',
  contribution_profit: 'Gross profit − payment fees − shipping − handling.',
  ad_spend: 'Monthly ad spend you entered for channels that aren\'t connected, spread per day. Google and Meta Ads join in Phase 3.',
  profit_after_ads: 'Contribution profit − ad spend.',
  fixed_costs: 'Monthly fixed costs (apps, staff, rent) spread per day.',
  net_profit: 'Profit after ads − fixed costs. An estimate: shown only when fixed costs are entered.',
  margin: 'Profit after ads ÷ net sales.',
  orders: 'Orders placed in this period (cancelled and test orders excluded).',
  aov: 'Average order value: (gross sales − discounts) ÷ orders.',
};

const REASONS = { cost: 'Product cost', discount: 'Discount', shipping: 'Shipping', refund: 'Refund' };

export default function Profit(props) {
  const { tab, tabs, period, currency, locked, canExport } = props;
  const q = new URLSearchParams(window.location.search);
  const exportUrl = tab !== 'overview' && canExport && !locked
    ? appUrl(withQuery(`/app/profit/${tab}/export`, { range: period.key, from: period.key === 'custom' ? period.from : null, to: period.key === 'custom' ? period.to : null, interval: q.get('interval') }))
    : null;

  return (
    <Page heading={tab === 'overview' ? 'Profit' : `Profit · ${tabs[tab].label}`} subtitle={`${period.label} · ${date(period.from)} – ${date(period.to)}`}
      actions={<div className="si-row">{exportUrl && <Button href={exportUrl} target="_blank">Export CSV</Button>}<PeriodPicker period={period} /></div>}>
      {period.locked && <Upgrade feature="profit_dashboard" heading="See any date range and your full history">Your plan shows the last 30 days.</Upgrade>}
      {locked ? <Upgrade feature={locked} /> : (
        <>
          {tab === 'overview' && <Overview {...props} />}
          {tab === 'products' && <Products {...props} />}
          {tab === 'orders' && <Orders {...props} />}
          {tab === 'channels' && <Channels {...props} />}
          {tab === 'sales' && <Sales {...props} />}
        </>
      )}
    </Page>
  );
}

function Overview({ profit: p, previous: prev, currency: c }) {
  const kpis = [
    ['net_sales', 'Net sales', money(p.net_sales, c)],
    ['profit_after_ads', 'Profit after ads', money(p.profit_after_ads, c)],
    ['margin', 'Margin', p.margin === null ? '—' : percent(p.margin)],
    ['ad_spend', 'Ad spend', money(p.ad_spend, c), true],
    ['orders', 'Orders', number(p.orders)],
    ['aov', 'Avg. order value', money(p.aov, c)],
  ];
  return (
    <>
      {p.coverage.percent !== null && (
        <a className={`coverage-badge ${p.coverage.percent >= 95 ? 'ok' : 'warn'}`} href={appUrl(route('app.products.index'))}>
          <b>{percent(p.coverage.percent, 0)}</b> of units sold have a product cost ({number(p.coverage.units_with_cost)} of {number(p.coverage.units)}, gift cards excluded)
          {p.coverage.percent < 100 && <span>Fix missing costs →</span>}
        </a>
      )}
      <div className="si-grid-6">
        {kpis.map(([key, label, value, inverse]) => (
          <div className="kpi" key={key}>
            <small><Term help={HELP[key]}>{label}</Term></small>
            <b className={key === 'profit_after_ads' && p.profit_after_ads < 0 ? 'neg' : ''}>{value}</b>
            <span><Delta now={p[key]} before={prev[key]} inverse={inverse} /> vs previous period</span>
          </div>
        ))}
      </div>

      <section className="hero-profit" aria-label="Daily profit">
        <div>
          <span className="label">Profit after ads</span>
          <span className={`big${p.profit_after_ads < 0 ? ' neg' : ''}`}>{money(p.profit_after_ads, c)}</span>
          <div className="meta"><Pill value={prev.profit_after_ads ? ((p.profit_after_ads - prev.profit_after_ads) / Math.abs(prev.profit_after_ads)) * 100 : null} /></div>
          <dl>
            <div><dt>Gross profit</dt><dd>{money(p.gross_profit, c)}</dd></div>
            <div><dt>Contribution</dt><dd>{money(p.contribution_profit, c)}</dd></div>
            <div><dt>Units sold</dt><dd>{number(p.units)}</dd></div>
            <div><dt>Returns</dt><dd>{money(p.returns, c)}</dd></div>
          </dl>
        </div>
        <DailyChart daily={p.daily} currency={c} />
      </section>

      <Section heading="Profit waterfall" description="From gross sales down to profit. Hover any line to see how it's calculated.">
        <Waterfall p={p} />
        {p.assumed_cogs > 0 && <p className="muted small">Product costs include {money(p.assumed_cogs, c)} assumed from your default margin for products without a cost.</p>}
      </Section>
    </>
  );
}

function Waterfall({ p }) {
  const c = p.currency;
  const rows = [
    ['gross_sales', 'Gross sales', p.gross_sales, 'sub'],
    ['discounts', 'Discounts', -p.discounts],
    ['returns', 'Returns', -p.returns],
    ['net_sales', 'Net sales', p.net_sales, 'sub'],
    ['cogs', 'Product costs', -p.cogs],
    ['gross_profit', 'Gross profit', p.gross_profit, 'sub'],
    ['payment_fees', 'Payment fees', -p.payment_fees],
    ['shipping_costs', 'Shipping', -p.shipping_costs],
    ['handling_costs', 'Handling', -p.handling_costs],
    ['contribution_profit', 'Contribution profit', p.contribution_profit, 'sub'],
    ['ad_spend', 'Ad spend', -p.ad_spend],
    ['profit_after_ads', 'Profit after ads', p.profit_after_ads, 'total'],
  ];
  if (p.net_profit !== null) rows.push(['fixed_costs', 'Fixed costs', -p.fixed_costs], ['net_profit', 'Estimated net profit', p.net_profit, 'total']);
  const base = Math.max(1, Math.abs(p.gross_sales));
  return (
    <table className="waterfall">
      <tbody>
        {rows.map(([key, label, value, kind]) => (
          <tr key={key} className={kind || ''}>
            <td><Term help={HELP[key]}>{label}</Term></td>
            <td className="wf-bar"><i className={kind ? (value < 0 ? 'neg' : 'pos') : 'cost'} style={{ width: `${Math.min(100, (Math.abs(value) / base) * 100)}%` }} /></td>
            <td className="num">{kind ? money(value, c) : value === 0 ? <span className="muted">{money(0, c)}</span> : `− ${money(Math.abs(value), c)}`}</td>
            <td className="num muted small">{p.net_sales > 0 && key !== 'gross_sales' ? percent((Math.abs(value) / p.net_sales) * 100, 0) : ''}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Filters({ options, current, param = 'sort' }) {
  const url = useUrl();
  return (
    <nav className="oo-tabs" aria-label="Sort">
      {options.map(([key, label]) => (
        <a key={key} href={appUrl(withQuery(url, { [param]: key, page: null }))} aria-current={current === key ? 'page' : undefined}>{label}</a>
      ))}
    </nav>
  );
}

function Products({ rows, currency: c }) {
  const q = new URLSearchParams(window.location.search);
  const sort = q.get('sort') || 'gross_profit';
  const url = useUrl();
  const only = q.get('only') === 'loss';
  return (
    <Section flush>
      <div className="toolbar">
        <Filters current={sort} options={[['gross_profit', 'Most profit'], ['net_sales', 'Net sales'], ['units', 'Units'], ['margin', 'Margin'], ['refund_rate', 'Refund rate']]} />
        <a className={`chip${only ? ' on' : ''}`} href={appUrl(withQuery(url, { only: only ? null : 'loss' }))}>{only ? '✓ ' : ''}Only loss-making</a>
      </div>
      {rows.length === 0 ? <div style={{ padding: 20 }}><Empty icon="tag" title={only ? 'No loss-making products' : 'No sales in this period'} /></div> : (
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Product</th><th className="num">Units</th><th className="num"><Term help={HELP.net_sales}>Net sales</Term></th><th className="num"><Term help={HELP.cogs}>Product costs</Term></th><th className="num"><Term help={HELP.gross_profit}>Gross profit</Term></th><th className="num">Margin</th><th className="num">Refunds</th><th className="num">Discounted</th></tr></thead>
            <tbody>
              {rows.map((p) => (
                <tr key={p.product_shopify_id || p.title} className={p.gross_profit < 0 ? 'loss' : ''}>
                  <td>
                    <div className="prod">
                      {p.image ? <img src={p.image} alt="" /> : <span className="ph" />}
                      <div>
                        {p.product_id ? <a href={appUrl(`/app/products/${p.product_id}`)}>{p.title}</a> : <strong>{p.title}</strong>}
                        {p.reason && <small><Badge tone="critical">Loss · {REASONS[p.reason]}</Badge></small>}
                        {!p.reason && p.missing_cost_units > 0 && <small><Badge tone="warning">{number(p.missing_cost_units)} units without cost</Badge></small>}
                      </div>
                    </div>
                  </td>
                  <td className="num">{number(p.units)}</td>
                  <td className="num">{money(p.net_sales, c)}</td>
                  <td className="num">{money(p.cogs, c)}</td>
                  <td className={`num ${p.gross_profit < 0 ? 'neg' : ''}`}>{money(p.gross_profit, c)}</td>
                  <td className="num">{p.margin === null ? '—' : percent(p.margin)}</td>
                  <td className="num">{p.refund_rate === null ? '—' : percent(p.refund_rate)}</td>
                  <td className="num">{p.discount_share === null ? '—' : percent(p.discount_share)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
}

function Orders({ rows, currency: c }) {
  const url = useUrl();
  return (
    <Section flush>
      <div className="toolbar">
        <Filters current={rows.sort} options={[['date', 'Newest'], ['profit', 'Lowest profit']]} />
        <a className={`chip${rows.only ? ' on' : ''}`} href={appUrl(withQuery(url, { only: rows.only ? null : 'loss', page: null }))}>{rows.only ? '✓ ' : ''}Only loss-making ({number(rows.losses)})</a>
      </div>
      {rows.data.length === 0 ? <div style={{ padding: 20 }}><Empty icon="cart" title={rows.only ? 'No loss-making orders' : 'No orders in this period'} /></div> : (
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Order</th><th>Channel · source</th><th className="num">Net sales</th><th className="num">Product costs</th><th className="num">Fees</th><th className="num">Shipping</th><th className="num">Profit</th><th className="num">Margin</th></tr></thead>
            <tbody>
              {rows.data.map((o) => (
                <tr key={o.id} className={o.profit < 0 ? 'loss' : ''}>
                  <td><a href={o.url} target="_top"><strong>{o.name}</strong></a><small className="muted block">{date(o.date)}{o.missing_cost ? ' · missing cost' : ''}</small></td>
                  <td>{o.channel}<small className="muted block">{o.source}</small></td>
                  <td className="num">{money(o.net_sales, c)}{o.refunds > 0 && <small className="muted block">refunded {money(o.refunds, c)}</small>}</td>
                  <td className="num">{money(o.cogs, c)}</td>
                  <td className="num">{money(o.fees, c)}</td>
                  <td className="num">{money(o.shipping, c)}</td>
                  <td className={`num ${o.profit < 0 ? 'neg' : ''}`}>{money(o.profit, c)}{o.reason && <small className="block"><Badge tone="critical">{REASONS[o.reason]}</Badge></small>}</td>
                  <td className="num">{o.margin === null ? '—' : percent(o.margin)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {rows.last_page > 1 && <div className="table-foot"><Pagination paginator={rows} url={url} /></div>}
      <p className="muted small table-foot">Profit per order is before ad spend, which isn't tracked per order. Loss reason is the cost that took the biggest share of the order.</p>
    </Section>
  );
}

function ChannelTable({ title, description, rows, c }) {
  const max = Math.max(1, ...rows.map((r) => r.net_sales));
  return (
    <Section heading={title} description={description} flush>
      {rows.length === 0 ? <div style={{ padding: 20 }}><Empty title="No orders in this period" /></div> : (
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>{title === 'Sales channels' ? 'Channel' : 'Source'}</th><th className="num">Orders</th><th className="num">Net sales</th><th style={{ width: '22%' }} /><th className="num">Profit before ads</th><th className="num">Margin</th><th className="num">AOV</th></tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.label}>
                  <td><strong>{r.label}</strong></td>
                  <td className="num">{number(r.orders)}</td>
                  <td className="num">{money(r.net_sales, c)}</td>
                  <td><div className="meter"><i style={{ width: `${(Math.max(0, r.net_sales) / max) * 100}%` }} /></div></td>
                  <td className={`num ${r.profit < 0 ? 'neg' : ''}`}>{money(r.profit, c)}</td>
                  <td className="num">{r.margin === null ? '—' : percent(r.margin)}</td>
                  <td className="num">{money(r.aov, c)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
}

function Channels({ channels, sources, currency: c }) {
  return (
    <>
      <ChannelTable title="Sales channels" description="Where the order was placed in Shopify." rows={channels} c={c} />
      <ChannelTable title="Marketing sources" description="From UTM tags, then the referring site; “Direct” when neither is known. Based on the order's landing details — not proof of what caused the sale." rows={sources} c={c} />
    </>
  );
}

function Sales({ trend, interval, previous: prev, profit: p, currency: c }) {
  const url = useUrl();
  const max = Math.max(1, ...trend.map((r) => r.net_sales));
  const fmt = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-US', interval === 'month' ? { month: 'short', year: 'numeric' } : { month: 'short', day: 'numeric' });
  const kpis = [['gross_sales', 'Gross sales', money(p.gross_sales, c)], ['net_sales', 'Net sales', money(p.net_sales, c)], ['orders', 'Orders', number(p.orders)], ['units', 'Units', number(p.units)], ['aov', 'AOV', money(p.aov, c)], ['discounts', 'Discounts', money(p.discounts, c), true], ['returns', 'Refunds', money(p.returns, c), true]];
  return (
    <>
      <div className="si-grid-6">
        {kpis.map(([key, label, value, inverse]) => (
          <div className="kpi" key={key}><small><Term help={HELP[key]}>{label}</Term></small><b>{value}</b><span><Delta now={p[key]} before={prev[key]} inverse={inverse} /> vs previous period</span></div>
        ))}
      </div>
      <Section heading="Net sales over time" action={(
        <nav className="seg" aria-label="Interval">
          {[['day', 'Daily'], ['week', 'Weekly'], ['month', 'Monthly']].map(([k, l]) => <a key={k} aria-selected={interval === k} href={appUrl(withQuery(url, { interval: k }))}>{l}</a>)}
        </nav>
      )}>
        <div className="bars" role="img" aria-label="Net sales per period">
          {trend.map((r) => <span key={r.period} title={`${fmt(r.period)}: ${money(r.net_sales, c)} · ${r.orders} orders`}><i style={{ height: `${(Math.max(0, r.net_sales) / max) * 100}%` }} /></span>)}
        </div>
      </Section>
      <Section flush>
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>{interval === 'day' ? 'Day' : interval === 'week' ? 'Week of' : 'Month'}</th><th className="num">Gross sales</th><th className="num">Discounts</th><th className="num">Refunds</th><th className="num">Net sales</th><th className="num">Orders</th><th className="num">Units</th><th className="num">AOV</th></tr></thead>
            <tbody>
              {[...trend].reverse().map((r) => (
                <tr key={r.period}><td>{fmt(r.period)}</td><td className="num">{money(r.gross_sales, c)}</td><td className="num">{money(r.discounts, c)}</td><td className="num">{money(r.refunds, c)}</td><td className="num"><strong>{money(r.net_sales, c)}</strong></td><td className="num">{number(r.orders)}</td><td className="num">{number(r.units)}</td><td className="num">{money(r.aov, c)}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="muted small table-foot">Conversion rate appears once GA4 or Growvia is connected.</p>
      </Section>
    </>
  );
}
