import { useState } from 'react';
import { ActionButton, Check, Field, Form, useForm } from '../../components/form.jsx';
import { Badge, Button, Empty, Icon, Page, Section, Stack } from '../../components/ui.jsx';
import { route, useShared } from '../../router.jsx';

const BLANK = {
  payment_fee: { percent: '', fixed: '', gateway: '' },
  shipping: { amount: '', basis: 'order' },
  handling: { amount: '' },
  fixed_cost: { amount: '' },
  ad_spend: { amount: '', channel: '' },
};

const ICON = { payment_fee: 'card', shipping: 'truck', handling: 'package', fixed_cost: 'calendar', ad_spend: 'target' };

const HELP = {
  payment_fee: 'Charged on each order: a percentage of the order total plus a fixed amount. Add one per payment gateway, or one without a gateway for all orders.',
  shipping: 'What you pay to ship, per order or per item. Used until carrier costs are connected.',
  handling: 'Packaging and pick-and-pack, per order.',
  fixed_cost: 'Monthly costs like apps, staff or rent. Spread over each day; used for estimated net profit.',
  ad_spend: 'Monthly spend on ad channels that aren\'t connected (for example influencers or TikTok).',
};

/** Settings → Costs & fees (Final MVP F3). */
export default function Costs({ currency, types, rules, presets, gateways }) {
  const { can } = useShared();
  const [adding, setAdding] = useState(null);
  const grouped = Object.keys(types).map((type) => [type, rules.filter((r) => r.type === type)]);

  return (
    <Page heading="Costs & fees" subtitle="These costs turn gross profit into real profit. Changes apply to every report straight away, including past periods.">
      {grouped.map(([type, list]) => (
        <Section key={type} heading={types[type]} description={HELP[type]}>
          {list.length === 0 && adding !== type && <Empty title={`No ${types[type].toLowerCase()} yet`} />}
          {list.map((rule) => <RuleRow key={rule.id} rule={rule} currency={currency} editable={can.manage_costs} gateways={gateways} />)}
          {adding === type ? (
            <RuleForm type={type} rule={{ name: '', config: BLANK[type] }} currency={currency} gateways={gateways} onDone={() => setAdding(null)} presets={type === 'payment_fee' ? presets : []} />
          ) : can.manage_costs && (
            <div><Button onClick={() => setAdding(type)}>+ Add {types[type].toLowerCase()}</Button></div>
          )}
        </Section>
      ))}
    </Page>
  );
}

function RuleRow({ rule, currency, editable, gateways }) {
  const [editing, setEditing] = useState(false);
  if (editing) {
    return <RuleForm type={rule.type} rule={rule} currency={currency} gateways={gateways} onDone={() => setEditing(false)} />;
  }
  return (
    <div className="rule-row">
      <Icon name={ICON[rule.type] || 'tag'} />
      <div>
        <strong>{rule.name}</strong> {rule.active ? <Badge tone="success">Active</Badge> : <Badge>Paused</Badge>}
        <div className="muted small">{rule.summary}</div>
      </div>
      {editable && (
        <Stack direction="inline">
          <Button size="slim" onClick={() => setEditing(true)}>Edit</Button>
          <ActionButton size="slim" variant="tertiary" tone="critical" url={route('app.settings.costs.destroy', { rule: rule.id })} confirm={`Delete "${rule.name}"? Profit is recalculated without it.`}>Delete</ActionButton>
        </Stack>
      )}
    </div>
  );
}

function RuleForm({ type, rule, currency, gateways, onDone, presets = [] }) {
  const form = useForm({ type, name: rule.name, config: { ...BLANK[type], ...rule.config }, active: rule.active ?? true });
  const save = async () => {
    const res = rule.id ? await form.post(route('app.settings.costs.update', { rule: rule.id })) : await form.post(route('app.settings.costs.store'));
    if (res.ok) onDone();
  };
  const err = (k) => form.errors[`config.${k}`];

  return (
    <div className="rule-form">
      {presets.length > 0 && (
        <Stack direction="inline" paddingBlockEnd="small-200">
          <span className="muted small">Start from:</span>
          {presets.map((p) => (
            <button key={p.name} type="button" className="chip" onClick={() => form.setData({ ...form.data, name: p.name, config: { percent: p.percent, fixed: p.fixed, gateway: p.gateway } })}>{p.name}</button>
          ))}
        </Stack>
      )}
      <Form onSubmit={save}>
        <div className="form-grid">
          <Field label="Name" error={form.errors.name}><input {...form.bind('name')} placeholder="e.g. Shopify Payments" /></Field>
          {type === 'payment_fee' && (
            <>
              <Field label="Percent of order total" error={err('percent')}><input type="number" step="0.01" min="0" {...form.bind('config.percent')} /></Field>
              <Field label={`Fixed per order (${currency})`} error={err('fixed')}><input type="number" step="0.01" min="0" {...form.bind('config.fixed')} /></Field>
              <Field label="Payment gateway (optional)" error={err('gateway')} help={gateways.length ? `Seen on your orders: ${gateways.join(', ')}` : 'Empty: applies to every order without a more specific rule.'}>
                <input {...form.bind('config.gateway')} placeholder="shopify_payments" />
              </Field>
            </>
          )}
          {type === 'shipping' && (
            <>
              <Field label={`Amount (${currency})`} error={err('amount')}><input type="number" step="0.01" min="0" {...form.bind('config.amount')} /></Field>
              <Field label="Charged per">
                <select {...form.bind('config.basis')}><option value="order">Order</option><option value="item">Item</option></select>
              </Field>
            </>
          )}
          {['handling', 'fixed_cost', 'ad_spend'].includes(type) && (
            <Field label={`Amount (${currency}) ${type === 'handling' ? 'per order' : 'per month'}`} error={err('amount')}><input type="number" step="0.01" min="0" {...form.bind('config.amount')} /></Field>
          )}
          {type === 'ad_spend' && <Field label="Channel (optional)" error={err('channel')}><input {...form.bind('config.channel')} placeholder="e.g. Influencers" /></Field>}
        </div>
        {rule.id && <Check label="Active" {...form.bind('active', { type: 'checkbox' })} />}
        <Stack direction="inline" paddingBlockStart="small-200">
          <Button variant="primary" type="submit" loading={form.processing}>Save</Button>
          <Button variant="tertiary" onClick={onDone}>Cancel</Button>
        </Stack>
      </Form>
    </div>
  );
}
