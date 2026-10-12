// Charts shared by Home and Profit: trend pill, coverage ring, money-flow bars and the daily chart.
import { money } from './ui.jsx';

/** Trend pill on the dark hero. */
export function Pill({ value }) {
  if (value === null || value === undefined || !Number.isFinite(value)) return null;
  const dir = value > 0.5 ? 'up' : value < -0.5 ? 'down' : 'flat';
  return <span className={`pill ${dir}`}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(value))}% vs previous period</span>;
}

/** Cost-coverage donut: teal when complete enough, amber below 80%. */
export function Ring({ value }) {
  const v = Math.max(0, Math.min(100, value ?? 0));
  return (
    <svg viewBox="0 0 36 36" role="img" aria-label={`Cost coverage ${Math.round(v)}%`}>
      <circle cx="18" cy="18" r="15.5" fill="none" stroke="#E6EAF0" strokeWidth="4" />
      {v > 0 && <circle cx="18" cy="18" r="15.5" fill="none" stroke={v >= 80 ? '#0B6E67' : '#D98E04'} strokeWidth="4" strokeDasharray={`${(v / 100) * 97.4} 100`} strokeLinecap="round" transform="rotate(-90 18 18)" />}
    </svg>
  );
}

/** Gross sales down to profit after ads, as bars scaled to gross sales. */
export function Flow({ p }) {
  const c = p.currency;
  const rows = [
    ['Gross sales', p.gross_sales, '#2E4A6E', 'sub'],
    ['Discounts', -p.discounts, '#8FA9C6'],
    ['Returns', -p.returns, '#C2410C'],
    ['Product costs', -p.cogs, '#5B7FA8', null, p.assumed_cogs > 0 ? `incl. ${money(p.assumed_cogs, c)} assumed` : null],
    ['Payment fees', -p.payment_fees, '#8FA9C6'],
    ['Shipping', -p.shipping_costs, '#8FA9C6'],
    ['Handling', -p.handling_costs, '#8FA9C6'],
    ['Ad spend', -p.ad_spend, '#D97706'],
    ['Profit after ads', p.profit_after_ads, p.profit_after_ads < 0 ? '#B42318' : '#0B6E67', 'total'],
  ];
  if (p.net_profit !== null) {
    rows.push(['Fixed costs', -p.fixed_costs, '#8FA9C6'], ['Estimated net profit', p.net_profit, p.net_profit < 0 ? '#B42318' : '#0B6E67', 'total']);
  }
  const base = Math.max(1, Math.abs(p.gross_sales));
  return (
    <div className="flow">
      {rows.map(([name, value, color, kind, note]) => (
        <div key={name} className={`flow-row${kind ? ` ${kind === 'total' ? 'total' : ''}` : ''}`}>
          <span>{name}{note && <small>{note}</small>}</span>
          <div className="bar"><i style={{ width: `${Math.min(100, (Math.abs(value) / base) * 100)}%`, background: color }} /></div>
          <span className={kind === 'total' && value < 0 ? 'neg' : ''}>
            {kind ? money(value, c) : value === 0 ? <span className="muted">{money(0, c)}</span> : `− ${money(Math.abs(value), c)}`}
          </span>
        </div>
      ))}
    </div>
  );
}

export function DailyChart({ daily, currency }) {
  const has = daily.some((d) => d.orders > 0 || d.net_sales !== 0);
  const first = daily[0]?.date;
  const last = daily[daily.length - 1]?.date;
  const fmt = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '');
  if (!has) {
    return <figure className="chart"><div className="chart-empty">No orders in this period yet.</div></figure>;
  }
  const max = Math.max(1, ...daily.map((d) => Math.max(d.net_sales, d.profit, 0)));
  const min = Math.min(0, ...daily.map((d) => d.profit));
  const span = max - min || 1;
  const zero = ((0 - min) / span) * 100; // % from the bottom
  return (
    <figure className="chart">
      <div className="chart-bars" role="img" aria-label="Daily net sales and profit">
        {min < 0 && <div className="zero" style={{ bottom: `${zero}%` }} />}
        {daily.map((d) => {
          const sales = (Math.max(0, d.net_sales) / span) * 100;
          const profit = (Math.abs(d.profit) / span) * 100;
          return (
            <div className="day" key={d.date} title={`${fmt(d.date)}: net sales ${money(d.net_sales, currency)}, profit ${money(d.profit, currency)}`}>
              <i style={{ bottom: `${zero}%`, height: `${sales}%` }} />
              <b className={d.profit < 0 ? 'neg' : ''} style={d.profit < 0 ? { top: `${100 - zero}%`, height: `${profit}%` } : { bottom: `${zero}%`, height: `${profit}%` }} />
            </div>
          );
        })}
      </div>
      <figcaption>
        <span><i className="sw" style={{ background: '#2E4A6E' }} />Net sales</span>
        <span><i className="sw" style={{ background: '#5EEAD4' }} />Profit</span>
        <span className="end">{fmt(first)} – {fmt(last)}</span>
      </figcaption>
    </figure>
  );
}
