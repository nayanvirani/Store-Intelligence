import { appUrl, pageComponent, route, useRouter } from './router.jsx';
import { Glyph } from './components/ui.jsx';

/**
 * The frame around every page: notices, then the page. Main sections live in Shopify's app menu
 * (s-app-nav, one level); a section with its own pages (Settings) lists them in a sidebar here.
 */
export default function App() {
  const { page, shared } = useRouter();
  const { menu = [], limit, legal } = shared;
  const section = menu.find((item) => item.active && item.children?.length);
  const notices = (limit || legal?.pages?.length) ? (
    <div className="si-notices">
      <Limit limit={limit} />
      <Legal legal={legal} />
    </div>
  ) : null;
  return (
    <div className={`si-frame${section ? ' with-side' : ''}`}>
      {section && <SectionNav section={section} />}
      <div className="si-content">{notices}<Current page={page} /></div>
    </div>
  );
}

function Current({ page }) {
  const Component = pageComponent(page.component);
  // Keyed by URL so a page's local state resets when you open another record.
  return <Component key={page.url.split('?')[0]} {...page.props} />;
}

const SECTION_ICONS = { General: 'settings', 'Costs & fees': 'tag', 'Store & data sync': 'store', Integrations: 'plug', 'Users & roles': 'users', Billing: 'card', Privacy: 'lock', Activity: 'list' };

/** The pages of the current section (e.g. Settings → General, Costs & fees…). */
function SectionNav({ section }) {
  return (
    <nav className="si-side" aria-label={section.label}>
      <span className="si-side-head">{section.label}</span>
      {section.children.map((c) => (
        <a key={c.href} href={appUrl(c.href)} aria-current={c.active ? 'page' : undefined}>
          <Glyph name={SECTION_ICONS[c.label] || 'list'} size={16} /><span>{c.label}</span>
        </a>
      ))}
    </nav>
  );
}

function Limit({ limit }) {
  if (!limit) return null;
  return (
    <div className={`ob-limit ob-limit-${limit.state}`} role="status">
      <div><strong>{limit.title}</strong> {limit.text}</div>
      <a href={appUrl('/app/settings/billing')}>{limit.action}</a>
    </div>
  );
}

/** Updated Terms or policies: links to each, and a button that records the review for this store. */
function Legal({ legal }) {
  const { submit, busy } = useRouter();
  if (!legal?.pages?.length) return null;
  const names = legal.pages.map((p, i) => (
    <span key={p.slug}>{i > 0 && (i === legal.pages.length - 1 ? ' and ' : ', ')}<a href={p.url} target="_blank" rel="noopener noreferrer">{p.title}</a></span>
  ));
  const effective = legal.pages[0].effective;
  return (
    <div className="ob-limit ob-legal" role="status">
      <div><strong>We've updated our {names}.</strong> {effective ? `Effective ${effective}. ` : ''}Please take a moment to review the changes.</div>
      <button type="button" disabled={busy} onClick={() => submit(route('app.legal.acknowledge'))}>I've reviewed them</button>
    </div>
  );
}
