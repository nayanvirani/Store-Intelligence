// Client-side navigation for the React admin. Pages come from the server as {component, props,
// shared}; links and forms work without reloading the iframe. Pages that are still Blade answer
// {legacy: true} and open with a normal page load.
import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';

const RouterContext = createContext(null);
const pages = import.meta.glob('./pages/**/*.jsx');
const loaded = {};

/** Loads a page's code (each page is its own chunk). */
export async function loadPage(name) {
  if (loaded[name]) return loaded[name];
  const loader = pages[`./pages/${name}.jsx`];
  if (!loader) throw new Error(`Unknown page: ${name}`);
  loaded[name] = (await loader()).default;
  return loaded[name];
}

/** A page's component, once loadPage has resolved it. */
export function pageComponent(name) {
  return loaded[name];
}

// The embedded-app parameters every URL keeps, so a refresh still knows the store.
const embed = (() => {
  const q = new URLSearchParams(window.location.search);
  return { shop: q.get('shop') || window.OO_PAGE?.shared?.shop, host: q.get('host') };
})();

/**
 * A named Laravel route as a path: route('app.settings.users.role', { user: 4 }).
 * Parameters that aren't in the URI become the query string.
 */
export function route(name, params = {}) {
  const uri = (window.OO_ROUTES || {})[name];
  if (!uri) throw new Error(`Unknown route: ${name}`);
  const query = { ...params };
  const path = uri.replace(/\{(\w+)\??\}/g, (_, key) => {
    const value = query[key];
    delete query[key];
    return value === undefined || value === null ? '' : encodeURIComponent(value);
  }).replace(/\/+$/, '') || '/';
  const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
  return path + (qs ? `?${qs}` : '');
}

/** The URL with some query parameters changed (null removes one). */
export function withQuery(url, params) {
  const u = new URL(url, window.location.origin);
  Object.entries(params).forEach(([k, v]) => (v === null || v === undefined || v === '' ? u.searchParams.delete(k) : u.searchParams.set(k, v)));
  return u.pathname + u.search;
}

/** The current page's URL (without the embedded-app parameters). */
export function useUrl() {
  return useContext(RouterContext).page.url;
}

/** An app URL with the shop (and host) added. */
export function appUrl(path, params = {}) {
  const url = new URL(path, window.location.origin);
  Object.entries({ ...embed, ...params }).forEach(([k, v]) => {
    if (v !== null && v !== undefined && v !== '' && !url.searchParams.has(k)) url.searchParams.set(k, v);
  });
  return url.pathname + url.search;
}

/** The URL without the embedded-app parameters, as pages and the server compare it. */
function bare(path) {
  const url = new URL(path, window.location.origin);
  ['shop', 'host', 'id_token', 'embedded', 'hmac', 'locale', 'session', 'timestamp', 'notice'].forEach((k) => url.searchParams.delete(k));
  return url.pathname + url.search;
}

async function request(url, { method = 'GET', body } = {}) {
  const form = typeof FormData !== 'undefined' && body instanceof FormData;
  if (form && method !== 'POST') body.append('_method', method);
  const res = await fetch(appUrl(url), {
    method: form ? 'POST' : method,
    headers: { 'X-OO-Page': '1', Accept: 'application/json', ...(body && !form ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? (form ? body : JSON.stringify(body)) : undefined,
  });
  let data = {};
  try {
    data = await res.json();
  } catch (e) {
    data = {};
  }
  return { res, data };
}

export function toast(message, error = false) {
  if (message) window.shopify?.toast?.show(message, { isError: error });
}

const loading = (on) => {
  try {
    window.shopify?.loading?.(on);
  } catch (e) { /* App Bridge not ready */ }
};

export function RouterProvider({ initial, children }) {
  const [page, setPage] = useState(initial);
  const pageRef = useRef(initial);
  const [busy, setBusy] = useState(false);
  const seq = useRef(0);

  const show = useCallback(async (data, { push = true, replace = false, preserveScroll = false } = {}) => {
    await loadPage(data.component);
    pageRef.current = data;
    setPage(data);
    const url = appUrl(data.url);
    if (replace || !push) {
      if (bare(window.location.pathname + window.location.search) !== bare(url) || replace) window.history.replaceState({ oo: true }, '', url);
    } else {
      window.history.pushState({ oo: true }, '', url);
    }
    if (!preserveScroll) window.scrollTo(0, 0);
  }, []);

  /** Handles any server answer to a visit or form submit. Returns {ok, errors}. */
  const handle = useCallback(async (res, data, url, opts) => {
    if (data.component) {
      // A form that answers with a page (e.g. validation shown in place) replaces the entry.
      // It keeps the current URL: the POST address may be a different page on GET.
      await show(opts.method ? { ...data, url: pageRef.current.url } : data, opts.method ? { replace: true, preserveScroll: true } : opts);
      return { ok: res.ok, data };
    }
    if (data.redirect) {
      toast(data.notice?.message, data.notice?.error);
      if (data.external) {
        window.open(data.redirect, '_top');
        return { ok: true };
      }
      const same = bare(data.redirect) === bare(pageRef.current.url);
      // eslint-disable-next-line no-use-before-define
      await visitRef.current(data.redirect, { preserveScroll: same, replace: same });
      return { ok: true };
    }
    if (data.legacy) {
      window.location.assign(appUrl(url));
      return { ok: true };
    }
    if (res.status === 422) {
      const errors = Object.fromEntries(Object.entries(data.errors || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
      toast(Object.values(errors)[0] || data.message || 'Check the highlighted fields.', true);
      return { ok: false, errors };
    }
    if (res.status === 402) {
      window.location.assign(appUrl('/app/settings/billing'));
      return { ok: false };
    }
    if (res.status === 403 && opts.method === undefined) {
      await show({ component: 'forbidden', props: { message: data.message }, shared: pageRef.current.shared, url }, opts);
      return { ok: false };
    }
    toast(data.message || 'We couldn\'t complete that request. Please try again.', true);
    return { ok: false, errors: {} };
  }, [show]);

  const visit = useCallback(async (url, opts = {}) => {
    const id = ++seq.current;
    setBusy(true);
    loading(true);
    try {
      const { res, data } = await request(url);
      if (id !== seq.current) return { ok: false };
      return await handle(res, data, url, opts);
    } catch (e) {
      toast('We couldn\'t reach Store Intelligence. Check your connection and try again.', true);
      return { ok: false };
    } finally {
      if (id === seq.current) {
        setBusy(false);
        loading(false);
      }
    }
  }, [handle]);
  const visitRef = useRef(visit);
  visitRef.current = visit;

  const submit = useCallback(async (url, body = {}, { method = 'POST', confirm } = {}) => {
    if (confirm && !window.confirm(confirm)) return { ok: false, cancelled: true };
    loading(true);
    try {
      const { res, data } = await request(url, { method, body });
      return await handle(res, data, url, { method });
    } catch (e) {
      toast('We couldn\'t complete that request. Please try again.', true);
      return { ok: false };
    } finally {
      loading(false);
    }
  }, [handle]);

  const reload = useCallback(() => visit(pageRef.current.url, { replace: true, preserveScroll: true }), [visit]);

  // Links: app URLs open in place; external, new-tab, download and data-reload links behave normally.
  useEffect(() => {
    const onClick = (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const path = event.composedPath();
      const link = path.find((n) => n instanceof Element && n.hasAttribute('href') && (n.tagName === 'A' || n.tagName.startsWith('S-')));
      if (!link) return;
      if (path.some((n) => n instanceof Element && ((n.getAttribute('target') && n.getAttribute('target') !== '_self') || n.hasAttribute('download') || n.hasAttribute('data-reload')))) return;
      const url = new URL(link.getAttribute('href'), window.location.href);
      if (url.origin !== window.location.origin || !/^\/app(\/|$)/.test(url.pathname) || /\/(export|attachments)\b/.test(url.pathname)) return;
      if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) return;
      event.preventDefault();
      visit(bare(url.pathname + url.search));
    };
    // Shopify's app menu (s-app-nav).
    const onNavigate = (event) => {
      const href = event.target?.getAttribute?.('href');
      if (!href) return;
      event.preventDefault();
      const url = new URL(href, window.location.href);
      visit(bare(url.pathname + url.search));
    };
    const onPop = () => visit(bare(window.location.pathname + window.location.search), { push: false, preserveScroll: true });
    document.addEventListener('click', onClick);
    document.addEventListener('shopify:navigate', onNavigate);
    window.addEventListener('popstate', onPop);
    return () => {
      document.removeEventListener('click', onClick);
      document.removeEventListener('shopify:navigate', onNavigate);
      window.removeEventListener('popstate', onPop);
    };
  }, [visit]);

  return (
    <RouterContext.Provider value={{ page, busy, visit, submit, reload, shared: page.shared || {} }}>
      {children}
    </RouterContext.Provider>
  );
}

export function useRouter() {
  return useContext(RouterContext);
}

/** Who's signed in, permissions, plan and navigation for the current page. */
export function useShared() {
  return useContext(RouterContext).shared;
}
