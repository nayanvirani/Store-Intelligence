import { Badge, Banner, Button, date, Hero, number, Page, Section, Stack } from '../../components/ui.jsx';

const price = (v) => (v > 0 ? `$${Number.isInteger(Number(v)) ? Number(v) : Number(v).toFixed(2)}` : 'Free');
const STATUS = {
  PENDING: ['warning', 'Waiting for approval', 'Approve the plan on Shopify to activate it.'],
  FROZEN: ['critical', 'Frozen', 'Your Shopify account has a billing issue. Resolve it in Shopify to reactivate Store Intelligence. Your data is kept.'],
  DECLINED: ['warning', 'Not approved', 'The plan wasn\'t approved. Choose a plan below to continue.'],
  EXPIRED: ['warning', 'Approval expired', 'The approval request expired. Choose a plan below to continue.'],
  CANCELLED: ['warning', 'Cancelled', 'Your plan was cancelled. Choose a plan below to continue. Your data is kept.'],
};

export default function Billing(p) {
  const { effective, subscription, plans, compare, usage, pricingUrl, pricingPage, warnAt } = p;
  const planName = (key) => plans.find((x) => x.key === key)?.name || key;
  const status = !subscription && !(p.plan && p.planExpiresAt) && !p.testShop && STATUS[p.latestStatus];

  return (
    <Page heading={effective ? 'Billing & plans' : 'Choose a plan'} subtitle={effective ? 'Billed by Shopify on your Shopify invoice. Change or cancel any time.' : null}>
      {!effective && <Hero eyebrow="Plans" title={pricingPage.headline} lead={pricingPage.lead} />}
      {p.syncError && <Banner tone="critical">Your Shopify connection needs attention. Open Settings → Store to review it.</Banner>}

      {subscription ? (
        <Section heading="Current plan">
          <Stack direction="inline" alignItems="center">
            <h3 className="si-h3">{planName(subscription.plan)} · {subscription.price > 0 ? `${price(subscription.price)}/mo` : 'Free'}</h3>
            {subscription.trial_ends_at ? <Badge tone="info">Trial</Badge> : <Badge tone="success">Active</Badge>}
            {subscription.test && <Badge>Test charge</Badge>}
          </Stack>
          <p>
            {subscription.trial_ends_at && `Trial ends ${date(subscription.trial_ends_at)}. `}
            {subscription.current_period_ends_at && `Renews ${date(subscription.current_period_ends_at)}.`}
          </p>
          <Stack direction="inline">
            {pricingUrl && <Button variant="primary" href={pricingUrl} target="_top">Change plan</Button>}
            <Button variant="tertiary" href={p.billingUrl} target="_top">Open Shopify billing</Button>
          </Stack>
        </Section>
      ) : p.plan && p.planExpiresAt ? (
        <Banner tone="warning" heading="Your plan was cancelled">
          <p>You keep {planName(p.plan)} until {date(p.planExpiresAt)}, the end of the period you've paid for. Choose a plan to keep publishing after that. Your data is kept either way.</p>
          {pricingUrl && <Button slot="secondary-actions" href={pricingUrl} target="_top">Choose a plan</Button>}
        </Banner>
      ) : p.testShop ? (
        <Banner tone="info" heading="Test store"><p>This development store has {planName(effective)} access without a subscription, for testing only.</p></Banner>
      ) : status ? (
        <Banner tone={status[0]} heading={`Plan status: ${status[1]}`}><p>{status[2]}</p></Banner>
      ) : null}

      {effective && usage.length > 0 && (
        <Section heading="Usage on your plan">
          <div className="ui-grid">
            {usage.map((m) => {
              const pct = m.limit ? Math.min(100, Math.round((m.used / m.limit) * 100)) : 100;
              const tone = m.used >= m.limit ? 'full' : m.limit && m.used / m.limit >= warnAt ? 'near' : '';
              return (
                <div className="ob-kpi" key={m.meter}>
                  <small>{m.label}</small>
                  <div><strong>{number(m.used)}</strong> <span className="muted">/ {m.limit === 0 ? 'not included' : number(m.limit)}</span></div>
                  <div className="oo-meter"><i className={tone} style={{ width: `${pct}%` }} /></div>
                  {tone === 'full' && <span className="oo-muted oo-small">Limit reached: what's live keeps working.</span>}
                  {m.help && <span className="oo-muted oo-small">{m.help}</span>}
                </div>
              );
            })}
          </div>
          <p className="muted small">Anything not listed is unlimited on your plan.</p>
        </Section>
      )}

      <Section heading={effective ? 'Plans' : 'Pick a plan'}>
        <div className="ob-plans">
          {plans.map((plan) => {
            const current = effective === plan.key;
            return (
              <div key={plan.key} className={`ob-plan ${plan.badge ? 'featured' : ''} ${current ? 'current' : ''}`}>
                {current ? <span className="ob-badge">Current plan</span> : plan.badge ? <span className="ob-badge">{plan.badge}</span> : null}
                <h3>{plan.name}</h3>
                {plan.description && <p className="oo-muted" style={{ margin: '2px 0 0' }}>{plan.description}</p>}
                <div className="ob-price">{price(plan.price)} {plan.price > 0 && <small>/MO</small>}</div>
                <ul>{plan.features.map((f) => <li key={f}>{f}</li>)}</ul>
                {/* Shopify's hosted plan page (Managed Pricing); it returns to the app with ?charge_id. */}
                {pricingUrl && <Button variant={current ? 'secondary' : 'primary'} href={pricingUrl} target="_top">{current ? 'Manage plan' : 'Choose plan'}</Button>}
              </div>
            );
          })}
        </div>
        {!pricingUrl && <p className="muted">Only store owners can change the plan.</p>}
      </Section>

      <Section heading={pricingPage.compare_title || 'Compare plans'}>
        <div className="oo-scroll">
          <table className="oo-table ob-compare">
            <thead><tr><th />{plans.map((plan) => <th key={plan.key} className={plan.key === effective ? 'current' : ''}>{plan.name}</th>)}</tr></thead>
            <tbody>
              {compare.map((g) => [
                <tr key={g.group} className="group"><td colSpan={plans.length + 1}>{g.group}</td></tr>,
                ...g.rows.map((row) => (
                  <tr key={g.group + row.label}>
                    <td>{row.label}</td>
                    {plans.map((plan) => {
                      const v = row.values[plan.key];
                      return <td key={plan.key} className={plan.key === effective ? 'current' : ''}>{v === true ? '✓' : v === false || v == null ? '—' : v}</td>;
                    })}
                  </tr>
                )),
              ])}
            </tbody>
          </table>
        </div>
      </Section>

      <p className="muted small">{pricingPage.billing_note} {pricingPage.trial_note} Nothing is deleted when you change plans: features a lower plan doesn't include stop showing, and anything over its limits is paused until you upgrade again.</p>
    </Page>
  );
}
