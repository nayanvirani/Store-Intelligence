import { useRef, useState } from 'react';
import { ActionButton, Form } from '../../components/form.jsx';
import { dateTime, KeyValue, Page } from '../../components/ui.jsx';
import { appUrl, route, useRouter } from '../../router.jsx';
import { TONE } from './index.jsx';

export default function Ticket({ ticket, messages }) {
  const { submit } = useRouter();
  const files = useRef(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const closed = ticket.status === 'closed';

  const reply = async () => {
    const data = new FormData();
    data.append('body', body);
    [...(files.current?.files || [])].forEach((f) => data.append('attachments[]', f));
    setBusy(true);
    const r = await submit(route('app.support.reply', { ticket: ticket.id }), data);
    setBusy(false);
    if (r.ok) setBody('');
  };

  return (
    <Page heading={ticket.subject} back={route('app.support.index')} backLabel="Support">
      <s-section>
        <KeyValue rows={[
          ['Ticket', ticket.reference],
          ['Status', <s-badge tone={TONE[ticket.status]}>{ticket.status_label}</s-badge>],
          ['Category', `${ticket.category} · ${ticket.priority} priority`],
          ['Opened', dateTime(ticket.created_at)],
          ticket.resolution && ['Resolution', ticket.resolution],
        ]} />
      </s-section>

      <s-section heading="Conversation">
        <ol className="sp-thread">
          {messages.map((m) => (
            <li key={m.id} className={m.team ? 'sp-team' : 'sp-merchant'}>
              <div className="sp-meta"><strong>{m.author}</strong> <span className="oo-muted oo-small">{dateTime(m.created_at)}</span></div>
              <div className="sp-body">{m.body}</div>
              {m.attachments.map((a) => (
                <div className="oo-small" key={a.id}>
                  <s-link href={appUrl(route('app.support.attachment', { attachment: a.id }))} target="_blank">📎 {a.filename}</s-link> <span className="oo-muted">{a.kb} KB</span>
                </div>
              ))}
            </li>
          ))}
        </ol>
      </s-section>

      <s-section heading={closed ? 'Reopen with a reply' : 'Reply'}>
        <Form className="sp-form" onSubmit={reply}>
          <textarea rows={4} maxLength={10000} value={body} onChange={(e) => setBody(e.target.value)} aria-label="Your reply" />
          <input ref={files} type="file" multiple accept="image/*,application/pdf,text/plain,text/csv" aria-label="Attachments" />
          <div className="oo-inline"><s-button type="submit" variant="primary" loading={busy || undefined} disabled={!body.trim() || undefined}>Send reply</s-button></div>
        </Form>
        <div style={{ marginTop: 12 }}>
          <ActionButton variant="tertiary" url={route('app.support.close', { ticket: ticket.id })}>{closed ? 'Reopen ticket' : 'Close ticket: my issue is solved'}</ActionButton>
        </div>
      </s-section>
    </Page>
  );
}
