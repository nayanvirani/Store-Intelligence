import { useState } from 'react';
import { appUrl, route, useRouter, useShared, useUrl, withQuery } from '../../router.jsx';
import { Badge, Button, Empty, money, number, Page, Pagination, percent, Section } from '../../components/ui.jsx';

/**
 * Products & costs (Final MVP F15): missing costs first, sorted by what sells. Costs entered here stay
 * in Store Intelligence — Shopify's "Cost per item" is never changed.
 */
export default function Products({ filter, filters, q, currency, coverage, variants }) {
  const { can } = useShared();
  const url = useUrl();
  const { visit } = useRouter();
  const [search, setSearch] = useState(q || '');
  const tone = coverage.percent === null ? '' : coverage.percent >= 80 ? '' : 'near';

  return (
    <Page heading="Products & costs" subtitle="Costs you enter here stay in Store Intelligence. Shopify's “Cost per item” is never changed.">
      <Section>
        <div className="coverage">
          <div>
            <strong>{coverage.percent === null ? 'No products yet' : `${percent(coverage.percent, 0)} of variants have a cost`}</strong>
            <p className="muted small" style={{ marginTop: 2 }}>{number(coverage.with_cost)} of {number(coverage.total)} variants. Profit is only as accurate as your costs — start with your best sellers.</p>
          </div>
          <div className="meter"><i className={tone} style={{ width: `${coverage.percent || 0}%` }} /></div>
        </div>
      </Section>

      <Section flush>
        <div className="toolbar">
          <nav className="oo-tabs" aria-label="Filter">
            {Object.entries(filters).map(([key, label]) => (
              <a key={key} href={appUrl(withQuery(url, { filter: key, page: null }))} aria-current={filter === key ? 'page' : undefined}>{label}</a>
            ))}
          </nav>
          <form role="search" onSubmit={(e) => { e.preventDefault(); visit(withQuery(url, { q: search || null, page: null })); }}>
            <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search product or SKU" aria-label="Search product or SKU" />
          </form>
        </div>

        {variants.data.length === 0 ? (
          <div style={{ padding: 20 }}>
            <Empty icon="tag" title={filter === 'missing' ? 'Every variant has a cost' : 'No variants found'} text={filter === 'missing' ? 'Nice — profit uses a real cost for everything you sell.' : 'Products appear here after the first sync.'} />
          </div>
        ) : (
          <div className="oo-scroll">
            <table className="oo-table products">
              <thead>
                <tr><th>Product</th><th>SKU</th><th className="num">Price</th><th className="num">Unit cost</th><th className="num">Margin</th><th className="num">Sold · 30 d</th><th className="num">Stock</th></tr>
              </thead>
              <tbody>
                {variants.data.map((v) => (
                  <tr key={v.id}>
                    <td>
                      <div className="prod">
                        {v.image ? <img src={v.image} alt="" /> : <span className="ph" />}
                        <div>
                          <a href={v.shopify_url} target="_top">{v.product}</a>
                          {v.title && <small className="muted">{v.title}</small>}
                        </div>
                      </div>
                    </td>
                    <td className="muted mono small">{v.sku || '—'}</td>
                    <td className="num">{money(v.price, currency)}</td>
                    <td className="num"><CostCell v={v} currency={currency} editable={can.manage_costs} filter={filter} q={q} page={variants.page} /></td>
                    <td className="num">{v.margin === null ? '—' : <span className={v.margin < 0 ? 'neg' : v.margin >= 30 ? 'pos' : ''}>{percent(v.margin)}</span>}</td>
                    <td className="num">{number(v.units_30d)}<small className="muted block">{money(v.revenue_30d, currency)}</small></td>
                    <td className="num">{v.inventory === null ? <span className="muted small">Not tracked</span> : number(v.inventory)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {variants.last_page > 1 && <div style={{ padding: '14px 20px', borderTop: '1px solid var(--line)' }}><Pagination paginator={variants} url={url} /></div>}
      </Section>
    </Page>
  );
}

function CostCell({ v, currency, editable, filter, q, page }) {
  const { submit } = useRouter();
  const [value, setValue] = useState(v.app_cost ?? '');
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    const res = await submit(route('app.products.cost', { variant: v.id }), { cost: value === '' ? null : value, filter, q, page });
    setBusy(false);
    if (res.ok) setEditing(false);
  };

  if (!editing) {
    return (
      <span className="cost">
        {v.cost === null ? <Badge tone="warning">Missing</Badge> : money(v.cost, currency)}
        {v.source === 'shopify' && <small className="muted">from Shopify</small>}
        {v.source === 'app' && <small className="muted">set in app{v.shopify_cost !== null ? ` · Shopify ${money(v.shopify_cost, currency)}` : ''}</small>}
        {editable && <button type="button" className="link small" onClick={() => setEditing(true)}>{v.cost === null ? 'Add cost' : 'Edit'}</button>}
      </span>
    );
  }
  return (
    <span className="cost-edit">
      <input type="number" min="0" step="0.01" value={value} onChange={(e) => setValue(e.target.value)} placeholder={v.shopify_cost ?? '0.00'} autoFocus aria-label={`Unit cost for ${v.product}`}
        onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') setEditing(false); }} />
      <Button size="slim" variant="primary" loading={busy} onClick={save}>Save</Button>
      <button type="button" className="link small" onClick={() => setEditing(false)}>Cancel</button>
      {v.app_cost !== null && <small className="muted block">Clear the field to use Shopify's cost.</small>}
    </span>
  );
}
