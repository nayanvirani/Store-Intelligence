import { appUrl, pageComponent, route, useRouter } from './router.jsx';
import { Glyph, Logo } from './components/ui.jsx';

/** The frame around every page: the app's left sidebar (sections with nested pages), notices, then the page. */
export default function App() {
  const { page, shared } = useRouter();
  const { menu = [], limit, legal } = shared;
  const notices = (limit || legal?.pages?.length) ? (
    <div className="si-notices">
      <Limit limit={limit} />
      <Legal legal={legal} />
    </div>
  ) : null;
  return (
    <div className={`si-frame${menu.length ? ' with-side' : ''}`}>
      {menu.length > 0 && <Sidebar menu={menu} />}
      <div className="si-content">{notices}<Current page={page} /></div>
    </div>
  );
}

function Current({ page }) {
  const Component = pageComponent(page.component);
  // Keyed by URL so a page's local state resets when you open another record.
  return <Component key={page.url.split('?')[0]} {...page.props} />;
}

/** Main sections; the active one opens to show its pages (like Settings in Shopify admin). */
function Sidebar({ menu }) {
  const shared = useRouter().shared;
  return (
    <nav className="si-side" aria-label="Store Intelligence">
      <a className="si-side-brand" href={appUrl(menu[0].href)}>
        <Logo size={28} />
        <span><strong>Store Intelligence</strong><small>{shared.storeName}{shared.planName ? ` · ${shared.planName}` : ''}</small></span>
      </a>
      {menu.map((item) => {
        const open = item.active && item.children?.length > 0;
        const childActive = open && item.children.some((c) => c.active);
        return (
          <div key={item.href} className={`si-side-item${open ? ' open' : ''}`}>
            <a href={appUrl(item.href)} aria-current={item.active && !childActive ? 'page' : undefined} className={item.active ? 'on' : undefined}>
              <Glyph name={item.icon} size={17} /><span>{item.label}</span>
              {item.children?.length > 0 && <svg className="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d={open ? 'm6 9 6 6 6-6' : 'm9 6 6 6-6 6'} /></svg>}
            </a>
            {open && (
              <div className="si-side-children">
                {item.children.map((c) => (
                  <a key={c.href} href={appUrl(c.href)} aria-current={c.active ? 'page' : undefined}>{c.label}</a>
                ))}
              </div>
            )}
          </div>
        );
      })}
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
