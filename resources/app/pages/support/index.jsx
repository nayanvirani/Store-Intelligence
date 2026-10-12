import { useRef, useState } from 'react';
import { Field, Form } from '../../components/form.jsx';
import { ago, Badge, Button, Hero, Page, Section } from '../../components/ui.jsx';
import { appUrl, route, useRouter } from '../../router.jsx';

export const TONE = { open: 'info', pending: 'warning', resolved: 'success', closed: 'neutral' };

export default function Support({ tickets, categories, priorities, statuses, helpUrl, docsUrl }) {
  const { submit } = useRouter();
  const files = useRef(null);
  const blank = { category: Object.keys(categories)[0], priority: 'normal', subject: '', body: '' };
  const [data, setData] = useState(blank);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setData({ ...data, [k]: e.target.value });

  const send = async () => {
    const body = new FormData();
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    [...(files.current?.files || [])].forEach((f) => body.append('attachments[]', f));
    setBusy(true);
    const r = await submit(route('app.support.store'), body);
    setBusy(false);
    setErrors(r.errors || {});
  };
  const attachmentError = Object.entries(errors).find(([k]) => k.startsWith('attachments'))?.[1];

  return (
    <Page>
      <Hero eyebrow="Support" icon="message" tone="analytics" title="We're here to <em>help.</em>" lead="Open a ticket and the Store Intelligence team replies here, usually within one business day. Your store's setup details are attached automatically, so you don't need to explain them.">
        <Button href={helpUrl} target="_blank">Support policy</Button>
        <Button href={docsUrl} target="_blank" variant="tertiary">Contact page</Button>
      </Hero>

      <Section heading="Your tickets">
        {!tickets.length ? <p><span className="oo-muted">No tickets yet.</span></p> : (
          <table className="oo-table stack">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Category</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
              {tickets.map((t) => (
                <tr key={t.id}>
                  <td data-label="Ticket"><a href={appUrl(route('app.support.show', { ticket: t.id }))}>{t.reference}</a></td>
                  <td data-label="Subject">{t.subject}{t.status === 'pending' && <> <Badge tone="warning">New reply</Badge></>}</td>
                  <td data-label="Category">{t.category}</td>
                  <td data-label="Status"><Badge tone={TONE[t.status]}>{statuses[t.status]}</Badge></td>
                  <td data-label="Updated">{ago(t.updated_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      <Section heading="Open a ticket">
        <Form className="sp-form" onSubmit={send}>
          <div className="oo-form-row">
            <Field label="Category"><select value={data.category} onChange={set('category')}>{Object.entries(categories).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
            <Field label="Priority"><select value={data.priority} onChange={set('priority')}>{Object.entries(priorities).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
          </div>
          <Field label="Subject" error={errors.subject}>
            <input type="text" maxLength={160} value={data.subject} onChange={set('subject')} placeholder="Profit for last week looks too high" aria-invalid={errors.subject ? true : undefined} />
          </Field>
          <Field label="What's happening?" error={errors.body}>
            <textarea rows={6} maxLength={10000} value={data.body} onChange={set('body')} placeholder="What did you expect, what happened instead, and which page or number is it about?" aria-invalid={errors.body ? true : undefined} />
          </Field>
          <Field label="Attachments (optional, up to 3 files, 4 MB each)" error={attachmentError}>
            <input ref={files} type="file" multiple accept="image/*,application/pdf,text/plain,text/csv" />
          </Field>
          <div><Button type="submit" variant="primary" loading={busy}>Send ticket</Button></div>
        </Form>
      </Section>
    </Page>
  );
}
