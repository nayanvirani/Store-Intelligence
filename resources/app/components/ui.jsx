// Shared building blocks for the React admin: Polaris web components plus the app's own tiles,
// styled by the same CSS as the Blade pages (app-brand.css, app-base.css).
import { appUrl, route, useShared } from '../router.jsx';
import { ICONS } from './icons.js';

/** A page: Polaris s-page at the app's width, with an optional breadcrumb and actions. */
export function Page({ heading, back, backLabel, primary, secondary, children }) {
  return (
    <s-page inlineSize="large" heading={heading}>
      {back && <s-link slot="breadcrumb-actions" href={appUrl(back)}>{backLabel || 'Back'}</s-link>}
      {primary}
      {secondary}
      {children}
    </s-page>
  );
}

/** Feature icon badge. */
export function Icon({ name, size, tone, className = '' }) {
  return (
    <span className={`ob-icon${size ? ` ${size}` : ''}${tone ? ` t-${tone}` : ''} ${className}`} aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" dangerouslySetInnerHTML={{ __html: ICONS[name] || ICONS.sparkle }} />
    </span>
  );
}

/** Placeholder lines while something loads. */
export function Skeleton({ lines = 3 }) {
  return (
    <div className="ui-skeleton" aria-busy="true" aria-label="Loading">
      {Array.from({ length: lines }).map((_, i) => <span key={i} style={{ width: `${90 - i * 18}%` }} />)}
    </div>
  );
}

/** A card's error with a retry button. */
export function LoadError({ error, onRetry }) {
  return (
    <s-banner tone="critical">
      {error?.message || 'This couldn\'t load.'} <s-button variant="tertiary" onClick={onRetry}>Try again</s-button>
    </s-banner>
  );
}

/** Tiny line chart for a list of numbers. */
export function Sparkline({ values = [], height = 32 }) {
  const max = Math.max(...values, 0);
  if (values.length < 2 || max <= 0) return null;
  const step = 100 / (values.length - 1);
  const points = values.map((v, i) => `${(i * step).toFixed(2)},${(height - (v / max) * (height - 4) - 2).toFixed(2)}`).join(' ');
  return (
    <svg className="ui-spark" viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" aria-hidden="true">
      <polyline points={`0,${height} ${points} 100,${height}`} className="area" />
      <polyline points={points} className="line" />
    </svg>
  );
}

/** Percentage change pill. */
export function Trend({ value }) {
  if (value === null || value === undefined) return null;
  const dir = value > 0.5 ? 'up' : value < -0.5 ? 'down' : 'flat';
  return <span className={`ui-trend ${dir}`}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(value))}%</span>;
}

/** Definition list (label: value rows). */
export function KeyValue({ rows }) {
  return (
    <dl className="oo-kv">
      {rows.filter(Boolean).map(([k, v]) => [<dt key={`${k}-t`}>{k}</dt>, <dd key={`${k}-d`}>{v ?? '—'}</dd>])}
    </dl>
  );
}

/** Pill tabs; each item is [label, href, active]. */
export function Tabs({ items, className = 'oo-tabs' }) {
  return (
    <nav className={className}>
      {items.map(([label, href, active]) => (
        <a key={label} href={appUrl(href)} aria-current={active ? 'page' : undefined} className={active ? 'on' : undefined}>{label}</a>
      ))}
    </nav>
  );
}

/** Previous / next for a paginated list. */
export function Pagination({ paginator, url }) {
  if (!paginator || paginator.last_page <= 1) return null;
  const to = (n) => {
    const u = new URL(url, window.location.origin);
    u.searchParams.set('page', n);
    return appUrl(u.pathname + u.search);
  };
  return (
    <s-stack direction="inline" gap="small-200" alignItems="center" paddingBlockStart="base">
      <s-button disabled={paginator.page <= 1 || undefined} href={paginator.page > 1 ? to(paginator.page - 1) : undefined}>Previous</s-button>
      <span className="oo-muted oo-small">Page {paginator.page} of {paginator.last_page}</span>
      <s-button disabled={paginator.page >= paginator.last_page || undefined} href={paginator.page < paginator.last_page ? to(paginator.page + 1) : undefined}>Next</s-button>
    </s-stack>
  );
}

/** A friendly empty state. */
export function Empty({ title, text, action, icon }) {
  return (
    <div className="ui-empty">
      {icon && <Icon name={icon} size="lg" />}
      {title && <strong>{title}</strong>}
      {text && <p>{text}</p>}
      {action}
    </div>
  );
}

export function money(value, currency = 'USD') {
  if (value === null || value === undefined || value === '') return '—';
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).format(value);
  } catch (e) {
    return `${currency} ${Number(value).toFixed(2)}`;
  }
}

export function number(value) {
  return value === null || value === undefined ? '—' : new Intl.NumberFormat().format(value);
}

export function percent(value, digits = 1) {
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(digits)}%`;
}

/** "Oct 4, 2026" */
export function date(value) {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/** "Oct 4, 2026, 9:41 PM" */
export function dateTime(value) {
  if (!value) return '—';
  return new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** "3 hours ago" */
export function ago(value) {
  if (!value) return 'never';
  const seconds = (new Date(value).getTime() - Date.now()) / 1000;
  const units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size || unit === 'second') return rtf.format(Math.round(seconds / size), unit);
  }
  return '';
}

/** Page intro: optional icon, title (may contain <em> for the accent), lead and actions. */
export function Hero({ eyebrow, title, lead, icon, tone, children }) {
  return (
    <section className={`ob-hero${tone ? ` t-${tone}` : ''}`}>
      {icon && <Icon name={icon} size="lg" />}
      <div className="ob-hero-main">
        {eyebrow && <span className="ob-eyebrow">{eyebrow}</span>}
        <h1 className="ob-title" dangerouslySetInnerHTML={{ __html: title }} />
        {lead && <p className="ob-lead">{lead}</p>}
        {children && <div className="ob-actions">{children}</div>}
      </div>
    </section>
  );
}

/** Illustrated empty state (same art as the Blade pages). */
export function EmptyState({ title, text, children }) {
  return (
    <div className="ob-empty">
      <svg viewBox="0 0 120 90" aria-hidden="true">
        <ellipse cx="60" cy="80" rx="42" ry="6" fill="#eef2ff" />
        <ellipse cx="60" cy="44" rx="50" ry="16" fill="none" stroke="#c7c9f7" strokeWidth="2" strokeDasharray="4 5" transform="rotate(-14 60 44)" />
        <rect x="38" y="24" width="44" height="40" rx="10" fill="#4f46e5" />
        <path d="M38 38h44" stroke="#7c74ff" strokeWidth="3" />
        <rect x="54" y="20" width="12" height="16" rx="3" fill="#a5a0ff" />
        <circle cx="103" cy="30" r="6" fill="#f59e0b" />
        <circle cx="16" cy="56" r="4" fill="#10b981" />
        <path d="M96 62l3 3 5-6" stroke="#10b981" strokeWidth="2.5" fill="none" strokeLinecap="round" />
      </svg>
      <h3>{title}</h3>
      {text && <p>{text}</p>}
      {children}
    </div>
  );
}

/** KPI tile: trend is a % change vs the previous period; spark is a list of numbers. */
export function Kpi({ label, value, sub, trend, spark, icon, tone }) {
  const dir = trend > 0.5 ? 'up' : trend < -0.5 ? 'down' : 'flat';
  const max = spark ? Math.max(...spark, 0) : 0;
  let line = null;
  if (spark && spark.length > 1 && max > 0) {
    line = 'M' + spark.map((v, i) => `${((i / (spark.length - 1)) * 100).toFixed(2)},${(30 - (v / max) * 26).toFixed(2)}`).join(' L');
  }
  return (
    <div className={`ob-kpi${tone ? ` t-${tone}` : ''}`}>
      <small>{icon && <Icon name={icon} size="sm" />}{label}</small>
      <b>
        {value}
        {trend !== null && trend !== undefined && <em className={`ob-trend ${dir}`}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(trend))}%</em>}
      </b>
      {sub && <span>{sub}</span>}
      {line && (
        <svg className="ob-spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true">
          <path className="area" d={`${line} L100,32 L0,32 Z`} />
          <path className="line" d={line} vectorEffect="non-scaling-stroke" />
        </svg>
      )}
    </div>
  );
}

/** "order" / "orders" */
export function plural(word, n) {
  return n === 1 ? word : `${word}s`;
}

/**
 * A locked feature: names the plan that unlocks it (from the super admin's plans) and links to
 * Billing with ?from=<feature>, so upgrades can be traced back to what prompted them.
 */
export function Upgrade({ feature, heading, children }) {
  const { featurePlans = {}, featureLabels = {} } = useShared();
  const plan = featurePlans[feature];
  const label = featureLabels[feature] || 'This feature';
  return (
    <s-banner tone="info" heading={heading || (plan ? `${label} is on the ${plan} plan` : `${label} isn't included in your plan`)}>
      <s-paragraph>{children || (plan ? `You can set it up now; it works on your store once you're on ${plan}.` : 'Contact us if you need it.')}</s-paragraph>
      <s-button slot="secondary-actions" href={appUrl(route('app.settings.billing'), { from: feature })}>{plan ? `Upgrade to ${plan}` : 'See plans'}</s-button>
    </s-banner>
  );
}
