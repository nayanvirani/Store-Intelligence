import { useState } from 'react';
import { route, useRouter, useShared } from '../../router.jsx';
import { Badge, Button, date, Empty, money, number, Page, percent, Section } from '../../components/ui.jsx';
import { Delta, Term } from '../../components/period.jsx';

const FIELDS = { price: 'Price', compare_at_price: 'Compare-at price', cost: 'Cost', status: 'Status', inventory: 'Stock' };

/** One product: last 30 days, where each $1 of sales goes, variants with stock forecast, and history. */
export default function ProductDetail({ product, stats: s, previous: prev, variants, costHistory, changes, currency: c }) {
  const variantName = Object.fromEntries(variants.map((v) => [v.id, v.title]));
  const multi = variants.length > 1;
  return (
    <Page heading={product.title} back={route('app.products.index')} backLabel="Products"
      subtitle={[product.status && product.status.toLowerCase(), product.vendor, product.type, `${variants.length} ${variants.length === 1 ? 'variant' : 'variants'}`].filter(Boolean).join(' · ')}
      actions={<Button href={product.shopify_url} target="_top">Open in Shopify</Button>}>
      {!s ? <Section><Empty icon="chart" title="No sales in the last 30 days" text="Results appear here once this product sells." /></Section> : (
        <>
          <div className="si-grid-6">
            {[['net_sales', 'Net sales', money(s.net_sales, c)], ['gross_profit', 'Gross profit', money(s.gross_profit, c)], ['margin', 'Margin', s.margin === null ? '—' : percent(s.margin)], ['units', 'Units sold', number(s.units)], ['refund_rate', 'Refund rate', s.refund_rate === null ? '—' : percent(s.refund_rate), true], ['discount_share', 'Discounted', s.discount_share === null ? '—' : percent(s.discount_share), true]].map(([k, label, value, inverse]) => (
              <div className="kpi" key={k}><small>{label}</small><b className={k === 'gross_profit' && s.gross_profit < 0 ? 'neg' : ''}>{value}</b><span><Delta now={s[k]} before={prev?.[k]} inverse={inverse} /> vs previous 30 days</span></div>
            ))}
          </div>
          <Section heading="Where each $1 of sales goes" description="Last 30 days, before payment fees, shipping and ads (they're per order, not per product).">
            <Split s={s} c={c} />
          </Section>
        </>
      )}

      <Section heading="Variants" description="Days of stock = units available ÷ average daily units sold (14- and 30-day windows)." flush>
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Variant</th><th className="num">Price</th><th className="num">Unit cost</th><th className="num">Margin</th><th className="num">Sold · 30 d</th><th className="num">Stock</th><th className="num"><Term help="At the last 14 days' pace · at the last 30 days' pace">Days of stock</Term></th></tr></thead>
            <tbody>
              {variants.map((v) => <VariantRow key={v.id} v={v} c={c} />)}
            </tbody>
          </table>
        </div>
      </Section>

      <div className="si-grid-2">
        <Section heading="Cost history" description="Each order uses the cost valid when it was placed.">
          {costHistory.length === 0 ? <Empty text="No cost recorded yet." /> : (
            <ul className="history">
              {costHistory.map((h, i) => (
                <li key={i}><span>{date(h.at)}{multi ? ` · ${variantName[h.variant_id]}` : ''}</span><span className="num">{h.cost === null ? 'No cost' : money(h.cost, c)} <small className="muted">{h.source === 'app' ? 'set in app' : h.source === 'import' ? 'imported' : 'from Shopify'}</small></span></li>
              ))}
            </ul>
          )}
        </Section>
        <Section heading="Price & status changes" description="Detected by Store Intelligence. Times are when we noticed the change.">
          {changes.length === 0 ? <Empty text="No changes detected yet." /> : (
            <ul className="history">
              {changes.map((h, i) => (
                <li key={i}><span>{date(h.at)}{multi ? ` · ${variantName[h.variant_id]}` : ''} · {FIELDS[h.field] || h.field}</span><span className="num">{fmt(h.field, h.before, c)} → <strong>{fmt(h.field, h.after, c)}</strong></span></li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </Page>
  );
}

function fmt(field, v, c) {
  if (v === null || v === undefined || v === '') return '—';
  return ['price', 'compare_at_price', 'cost'].includes(field) ? money(v, c) : String(v).toLowerCase();
}

function Split({ s, c }) {
  const base = Math.max(0.01, s.gross_sales);
  const parts = [['Product costs', s.cogs, '#5B7FA8'], ['Discounts', s.discounts, '#8FA9C6'], ['Refunds', s.refunds, '#C2410C'], ['Gross profit', Math.max(0, s.gross_profit), '#0B6E67']];
  return (
    <>
      <div className="split-bar">{parts.map(([l, v, col]) => <i key={l} title={`${l}: ${money(v, c)}`} style={{ width: `${(Math.max(0, v) / base) * 100}%`, background: col }} />)}</div>
      <div className="split-legend">
        {parts.map(([l, v, col]) => <span key={l}><i style={{ background: col }} />{l}<b className="num">{money(v, c)}</b><small className="muted">{Math.round((Math.max(0, v) / base) * 100)}¢</small></span>)}
      </div>
    </>
  );
}

function VariantRow({ v, c }) {
  const { can } = useShared();
  const { submit } = useRouter();
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(v.app_cost ?? '');
  const [busy, setBusy] = useState(false);
  const low = v.days_of_stock_14 !== null && v.days_of_stock_14 < 7;
  const save = async () => {
    setBusy(true);
    const r = await submit(route('app.products.cost', { variant: v.id }), { cost: value === '' ? null : value, back: 'product' });
    setBusy(false);
    if (r.ok) setEditing(false);
  };
  return (
    <tr>
      <td><strong>{v.title}</strong><small className="muted block mono">{v.sku || 'No SKU'}</small></td>
      <td className="num">{money(v.price, c)}{v.compare_at_price > v.price && <small className="muted block">was {money(v.compare_at_price, c)}</small>}</td>
      <td className="num">
        {editing ? (
          <span className="cost-edit">
            <input type="number" min="0" step="0.01" value={value} autoFocus onChange={(e) => setValue(e.target.value)} placeholder={v.shopify_cost ?? '0.00'} aria-label={`Cost for ${v.title}`} onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') setEditing(false); }} />
            <Button size="slim" variant="primary" loading={busy} onClick={save}>Save</Button>
          </span>
        ) : (
          <span className="cost">
            {v.cost === null ? <Badge tone="warning">Missing</Badge> : money(v.cost, c)}
            {can.manage_costs && <button type="button" className="link small" onClick={() => setEditing(true)}>{v.cost === null ? 'Add cost' : 'Edit'}</button>}
          </span>
        )}
      </td>
      <td className="num">{v.margin === null ? '—' : <span className={v.margin < 0 ? 'neg' : ''}>{percent(v.margin)}</span>}</td>
      <td className="num">{number(v.sold_30d)}</td>
      <td className="num">{v.inventory === null ? <span className="muted small">Not tracked</span> : number(v.inventory)}</td>
      <td className="num">
        {v.days_of_stock_14 === null ? <span className="muted">—</span> : <span className={low ? 'neg' : ''}>{v.days_of_stock_14} d</span>}
        {v.days_of_stock_30 !== null && <small className="muted block">{v.days_of_stock_30} d at 30-day pace</small>}
      </td>
    </tr>
  );
}
