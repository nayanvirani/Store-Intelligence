import { ActionButton } from '../components/form.jsx';
import { ago, Hero, number, Page } from '../components/ui.jsx';
import { appUrl, route, useShared } from '../router.jsx';

/**
 * Onboarding (Final MVP F2): five short steps from install to an accurate profit number.
 * Progress is worked out from the store itself, so it can be left and resumed at any time.
 */
export default function Onboarding({ store, checklist, sync, coverage, completed }) {
  const { can } = useShared();
  const done = checklist.filter((s) => s.done).length;
  const next = checklist.find((s) => !s.done);
  const costPct = coverage.total ? Math.round((coverage.with_cost / coverage.total) * 100) : 0;

  return (
    <Page heading="Get started">
      <Hero eyebrow="Get started" title="See your <em>real profit</em> in a few minutes." lead={`Five short steps for ${store.name}. You can leave at any time and pick up where you left off.`} />

      <s-section>
        <div className="setup-progress"><i style={{ width: `${(done / checklist.length) * 100}%` }} /></div>
        <p className="muted small">{done} of {checklist.length} done</p>
        <ol className="steps">
          {checklist.map((s, i) => (
            <li key={s.key} className={s.done ? 'done' : s === next ? 'current' : ''}>
              <span className="num">{s.done ? '✓' : i + 1}</span>
              <div>
                <strong>{s.label}</strong>
                <p className="muted">{s.help}</p>
                {s.key === 'synced' && (
                  <p className="small">
                    Products: {sync.products.running ? 'importing…' : sync.products.last_success_at ? `done ${ago(sync.products.last_success_at)}` : 'waiting'} ·
                    Orders: {sync.orders.running ? 'importing…' : sync.orders.last_success_at ? `done ${ago(sync.orders.last_success_at)}` : 'waiting'}
                  </p>
                )}
                {s.key === 'costs' && coverage.total > 0 && <p className="small">{number(coverage.with_cost)} of {number(coverage.total)} variants have a cost ({costPct}%).</p>}
                {!s.done && <s-button href={appUrl(s.href)}>{s.key === 'synced' ? 'View sync status' : 'Open'}</s-button>}
              </div>
            </li>
          ))}
        </ol>
      </s-section>

      {!completed && can.manage_settings && (
        <s-stack direction="inline" gap="small-200" paddingBlockStart="base">
          <ActionButton variant="primary" url={route('app.onboarding.complete')}>{done === checklist.length ? 'Finish setup' : 'Skip for now'}</ActionButton>
          <s-button variant="tertiary" href={appUrl(route('app.dashboard'))}>See my profit</s-button>
        </s-stack>
      )}
    </Page>
  );
}
