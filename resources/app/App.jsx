import { appUrl, pageComponent, route, useRouter } from './router.jsx';
import { Glyph, Logo } from './components/ui.jsx';

/** The frame around every page: top bar, settings sidebar, plan-limit and legal notices, then the page. */
export default function App() {
  const { page, shared } = useRouter();
  const { nav, limit, legal } = shared;
  const notices = (limit || legal?.pages?.length) ? (
    <div className="si-notices">
      <Limit limit={limit} />
      <Legal legal={legal} />
    </div>
  ) : null;
  return (
    <>
      <TopBar shared={shared} />
      {nav ? (
        <div className="si-frame with-side">
          <Sidebar nav={nav} />
          <div>{notices}<Current page={page} /></div>
        </div>
      ) : (
        <div className="si-frame">{notices}<Current page={page} /></div>
      )}
    </>
  );
}

function Current({ page }) {
  const Component = pageComponent(page.component);
  // Keyed by URL so a page's local state resets when you open another record.
  return <Component key={page.url.split('?')[0]} {...page.props} />;
}

function TopBar({ shared }) {
  const menu = shared.menu || [];
  return (
    <header className="si-top">
      <div className="si-top-in">
        <div className="si-brand">
          <Logo />
          <strong>Store Intelligence</strong>
          {shared.storeName && <><span className="sep">/</span><span className="store">{shared.storeName}{shared.planName ? ` · ${shared.planName}` : ''}</span></>}
        </div>
        {menu.length > 0 && (
          <nav className="si-nav" aria-label="Main">
            {menu.map((item) => (
              <a key={item.href} href={appUrl(item.href)} aria-current={item.active ? 'page' : undefined}>{item.label}</a>
            ))}
          </nav>
        )}
      </div>
    </header>
  );
}

function Sidebar({ nav }) {
  return (
    <nav className="si-side" aria-label={nav.label}>
      <span className="si-side-head">{nav.label}</span>
      {nav.groups.flatMap((group) => group.items).map((item) => (
        <a key={item.href + item.label} href={appUrl(item.href)} aria-current={item.active ? 'page' : undefined}>
          <Glyph name={item.icon} size={16} /><span>{item.label}</span>
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
