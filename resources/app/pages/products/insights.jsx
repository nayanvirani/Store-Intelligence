import { appUrl } from '../../router.jsx';
import { Badge, Empty, money, number, Page, percent, Section, Upgrade } from '../../components/ui.jsx';

/** What each list shows next to the product (besides net sales). */
const METRIC = {
  best_sellers: (p) => `${number(p.units)} units`,
  most_profitable: (p, c) => `${money(p.gross_profit, c)} profit`,
  advertise: (p) => `${percent(p.margin)} margin`,
  rising: (p) => (p.change === null ? 'new' : `↑ ${Math.round(p.change)}%`),
  falling: (p) => `↓ ${Math.abs(Math.round(p.change))}%`,
  high_refunds: (p) => `${percent(p.refund_rate)} refunded`,
  discount_dependent: (p) => `${percent(p.discount_share)} discounted`,
};
const TONE = { rising: 'pos', falling: 'neg', high_refunds: 'neg', discount_dependent: 'warn' };

/** Product insights (Final MVP F33): which products to push, fix or restock. */
export default function Insights({ locked, lists, currency: c, averageMargin }) {
  return (
    <Page heading="Product insights" subtitle={`Last 30 days compared with the 30 before.${averageMargin !== null && averageMargin !== undefined ? ` Your average margin: ${percent(averageMargin)}.` : ''}`}>
      {locked ? <Upgrade feature={locked} /> : (
        <div className="si-grid-2">
          {Object.entries(lists).map(([key, list]) => (
            <Section key={key} heading={list.label} description={list.rule}>
              {list.items.length === 0 ? <Empty text="No products match yet." /> : (
                <ol className="rank">
                  {list.items.map((p, i) => (
                    <li key={p.title + i}>
                      <span className="pos-n">{i + 1}</span>
                      {p.image ? <img src={p.image} alt="" /> : <span className="ph" />}
                      <div>
                        {p.product_id ? <a href={appUrl(`/app/products/${p.product_id}`)}>{p.title}</a> : <strong>{p.title}</strong>}
                        <small className="muted">
                          {money(p.net_sales, c)} net sales · {number(p.orders)} orders
                          {p.days_of_stock !== null && p.days_of_stock !== undefined ? ` · ${p.days_of_stock} days of stock` : ''}
                        </small>
                        {p.missing_cost && <small><Badge tone="warning">Missing cost</Badge></small>}
                      </div>
                      <b className={TONE[key] || ''}>{METRIC[key](p, c)}</b>
                    </li>
                  ))}
                </ol>
              )}
            </Section>
          ))}
        </div>
      )}
    </Page>
  );
}
