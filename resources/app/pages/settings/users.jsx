import { ActionButton, Field, Form, useForm } from '../../components/form.jsx';
import { ago, Page } from '../../components/ui.jsx';
import { route, useRouter } from '../../router.jsx';

const STATUS = { active: ['success', 'Active'], pending: ['info', 'Pending invite'], removed: ['critical', 'Access removed'] };

export default function Users({ users, assignable, roles, matrix }) {
  const { submit } = useRouter();
  const invite = useForm({ email: '', role: assignable.includes('analyst') ? 'analyst' : assignable[0] });

  return (
    <Page heading="Settings">
      <s-section heading="Staff">
        <s-paragraph>Everyone with access to Store Intelligence in Shopify admin appears here the first time they open the app. Give someone a role in advance by inviting their Shopify staff email.</s-paragraph>
        <div className="oo-scroll">
          <table className="oo-table stack">
            <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last active</th><th /></tr></thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>
                    <strong>{u.name}</strong>{u.me && <span className="oo-muted"> (you)</span>}
                    {u.email && u.name !== u.email && <div className="oo-muted oo-small">{u.email}</div>}
                    {u.account_owner && <div className="oo-muted oo-small">Shopify store owner</div>}
                  </td>
                  <td>
                    {u.locked || u.disabled ? roles[u.role] : (
                      <select className="oo-select" value={u.role} aria-label={`Role for ${u.name}`} onChange={(e) => submit(route('app.settings.users.role', { user: u.id }), { role: e.target.value })}>
                        {assignable.map((r) => <option key={r} value={r}>{roles[r]}</option>)}
                      </select>
                    )}
                  </td>
                  <td>{STATUS[u.status] && <s-badge tone={STATUS[u.status][0]}>{STATUS[u.status][1]}</s-badge>}</td>
                  <td className="oo-muted" data-label="Last active">{u.last_active_at ? ago(u.last_active_at) : '—'}</td>
                  <td style={{ textAlign: 'right' }}>
                    {!u.locked && (u.disabled ? (
                      <ActionButton variant="tertiary" url={route('app.settings.users.restore', { user: u.id })}>Restore access</ActionButton>
                    ) : (
                      <ActionButton variant="tertiary" tone="critical" url={route('app.settings.users.remove', { user: u.id })} confirm={u.pending ? 'Cancel this invite?' : `Remove ${u.name}'s access to Store Intelligence?`}>
                        {u.pending ? 'Cancel invite' : 'Remove'}
                      </ActionButton>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </s-section>

      <s-section heading="Invite staff">
        <Form className="oo-form-row" onSubmit={async () => { const r = await invite.post(route('app.settings.users.invite')); if (r.ok) invite.set('email', ''); }}>
          <Field label="Shopify staff email" className="grow" error={invite.errors.email}>
            <input type="email" required placeholder="name@yourstore.com" {...invite.bind('email')} style={{ minWidth: 260 }} />
          </Field>
          <Field label="Role">
            <select {...invite.bind('role')}>{assignable.map((r) => <option key={r} value={r}>{roles[r]}</option>)}</select>
          </Field>
          <s-button type="submit" variant="primary" loading={invite.processing || undefined}>Invite</s-button>
        </Form>
        <s-paragraph><span className="oo-muted oo-small">They also need access to Store Intelligence in Shopify admin (Settings → Users). When they first open the app, they get this role.</span></s-paragraph>
      </s-section>

      <s-section heading="What each role can do">
        <div className="oo-scroll">
          <table className="oo-table">
            <thead><tr><th>Permission</th>{Object.values(roles).map((label) => <th key={label} style={{ textAlign: 'center' }}>{label}</th>)}</tr></thead>
            <tbody>
              {matrix.map((p) => (
                <tr key={p.label}>
                  <td>{p.label}</td>
                  {Object.keys(roles).map((r) => <td key={r} style={{ textAlign: 'center' }}>{p.roles.includes(r) ? '✓' : <span className="oo-muted">—</span>}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </s-section>
    </Page>
  );
}
