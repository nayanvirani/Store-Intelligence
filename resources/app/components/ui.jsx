// Shared building blocks for the embedded app, styled by app.css (the Store Intelligence design
// system). They take the same props as the Polaris elements they replaced (heading, variant, tone,
// href, loading…), so pages read the same way.
import { Children, isValidElement } from 'react';
import { appUrl, route, useShared } from '../router.jsx';
import { ICONS } from './icons.js';

/** The app's mark: rising bars with a trend line. */
export function Logo({ size = 30 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true">
      <rect width="64" height="64" rx="15" fill="#0B6E67" />
      <rect x="14" y="36" width="8" height="14" rx="2" fill="#fff" opacity=".55" />
      <rect x="28" y="28" width="8" height="22" rx="2" fill="#fff" opacity=".8" />
      <rect x="42" y="20" width="8" height="30" rx="2" fill="#fff" />
      <path d="M13 30 L27 21 L35 25 L51 13" fill="none" stroke="#F6C453" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** A page: title, optional intro line, back link and actions on the right. */
export function Page({ heading, subtitle, back, backLabel, primary, secondary, actions, children }) {
  const right = [secondary, primary, actions].filter(Boolean);
  return (
    <div className="si-page">
      {back && <a className="si-back" href={appUrl(back)}>← {backLabel || 'Back'}</a>}
      {heading && (
        <header className="si-head">
          <div>
            <h1>{heading}</h1>
            {subtitle && <p>{subtitle}</p>}
          </div>
          {right.length > 0 && <div className="si-head-actions">{right}</div>}
        </header>
      )}
      {children}
    </div>
  );
}

/** A white card with an optional heading, description and header action. */
export function Section({ heading, description, action, flush, className = '', children }) {
  return (
    <section className={`si-card${flush ? ' flush' : ''} ${className}`}>
      {(heading || action) && (
        <header className="si-card-head">
          <div>
            {heading && <h2>{heading}</h2>}
            {description && <p>{description}</p>}
          </div>
          {action}
        </header>
      )}
      {children}
    </section>
  );
}

/**
 * Button or link-button. variant: primary | secondary | tertiary | dark; tone="critical" for
 * destructive actions; href renders a link (app links open in place through the router).
 */
export function Button({ variant, tone, size, href, target, type = 'button', loading, disabled, onClick, children, slot, className = '', ...rest }) {
  const cls = ['btn', variant && variant !== 'secondary' && variant, tone === 'critical' && 'critical', size === 'slim' && 'slim', className].filter(Boolean).join(' ');
  const body = <>{loading && <span className="spin" aria-hidden="true" />}{children}</>;
  if (href) {
    return (
      <a className={cls} href={href} target={target} rel={target === '_blank' ? 'noopener noreferrer' : undefined} aria-disabled={disabled ? 'true' : undefined} onClick={onClick} {...rest}>{body}</a>
    );
  }
  return (
    <button className={cls} type={type} disabled={disabled || loading} aria-busy={loading || undefined} onClick={onClick} {...rest}>{body}</button>
  );
}

/** Small status pill. tone: success | info | warning | critical | (neutral). */
export function Badge({ tone, dot, children }) {
  return <span className={`badge${tone && tone !== 'neutral' ? ` ${tone}` : ''}${dot ? ' dot' : ''}`}>{children}</span>;
}

const BANNER_ICONS = {
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></>,
  success: <><circle cx="12" cy="12" r="9" /><path d="m8.5 12.2 2.4 2.4 4.6-4.8" /></>,
  warning: <><path d="M12 3 2 20h20z" /><path d="M12 10v4M12 17h.01" /></>,
  critical: <><circle cx="12" cy="12" r="9" /><path d="M12 7v6M12 16.5h.01" /></>,
};

/** Notice with an icon. Children with slot="secondary-actions" become its buttons. */
export function Banner({ tone = 'info', heading, children }) {
  const all = Children.toArray(children);
  const actions = all.filter((c) => isValidElement(c) && c.props.slot === 'secondary-actions');
  const body = all.filter((c) => !actions.includes(c));
  return (
    <div className={`banner ${tone}`} role={tone === 'critical' ? 'alert' : 'status'}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{BANNER_ICONS[tone] || BANNER_ICONS.info}</svg>
      <div className="banner-body">
        {heading && <strong>{heading}</strong>}
        {body.length > 0 && <div>{body}</div>}
      </div>
      {actions.length > 0 && <div className="banner-actions">{actions}</div>}
    </div>
  );
}

/** Row (direction="inline") or column of items. */
export function Stack({ direction = 'block', alignItems, paddingBlockStart, paddingBlockEnd, children }) {
  const cls = [direction === 'inline' ? 'si-row' : 'si-col', paddingBlockStart && 'pad-top', paddingBlockEnd && 'pad-bottom'].filter(Boolean).join(' ');
  return <div className={cls} style={alignItems ? { alignItems } : undefined}>{children}</div>;
}

/** A bare line icon from icons.js. */
export function Glyph({ name, size = 18 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" dangerouslySetInnerHTML={{ __html: ICONS[name] || ICONS.sparkle }} />
  );
}

/** Icon in a soft brand tile. */
export function Icon({ name, className = '' }) {
  return <span className={`si-ico ${className}`} aria-hidden="true"><Glyph name={name} /></span>;
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
    <Banner tone="critical" heading={error?.message || 'This couldn\'t load.'}>
      <Button slot="secondary-actions" onClick={onRetry}>Try again</Button>
    </Banner>
  );
}

/** Percentage change pill. */
export function Trend({ value }) {
  if (value === null || value === undefined || !Number.isFinite(value)) return null;
  const dir = value > 0.5 ? 'up' : value < -0.5 ? 'down' : 'flat';
  return <span className={dir}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(value))}%</span>;
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
        <a key={label} href={appUrl(href)} aria-current={active ? 'page' : undefined}>{label}</a>
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
    <div className="si-row" style={{ justifyContent: 'space-between' }}>
      <span className="muted small">Page {paginator.page} of {paginator.last_page}</span>
      <div className="si-row">
        <Button size="slim" disabled={paginator.page <= 1} href={paginator.page > 1 ? to(paginator.page - 1) : undefined}>← Previous</Button>
        <Button size="slim" disabled={paginator.page >= paginator.last_page} href={paginator.page < paginator.last_page ? to(paginator.page + 1) : undefined}>Next →</Button>
      </div>
    </div>
  );
}

/** A friendly empty state. */
export function Empty({ title, text, action, icon }) {
  return (
    <div className="ui-empty">
      {icon && <Icon name={icon} />}
      {title && <strong>{title}</strong>}
      {text && <p>{text}</p>}
      {action}
    </div>
  );
}

/** Illustrated empty state. */
export function EmptyState({ title, text, children }) {
  return <Empty title={title} text={text} action={children} icon="sparkle" />;
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

/** Dark page intro: optional icon, title (may contain <em> for the accent), lead and actions. */
export function Hero({ eyebrow, title, lead, icon, children }) {
  return (
    <section className="ob-hero">
      {icon && <Icon name={icon} />}
      <div className="ob-hero-main">
        {eyebrow && <span className="ob-eyebrow">{eyebrow}</span>}
        <h2 className="ob-title" dangerouslySetInnerHTML={{ __html: title }} />
        {lead && <p className="ob-lead">{lead}</p>}
        {children && <div className="ob-actions">{children}</div>}
      </div>
    </section>
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
    <Banner tone="info" heading={heading || (plan ? `${label} is on the ${plan} plan` : `${label} isn't included in your plan`)}>
      <p>{children || (plan ? `You can set it up now; it works on your store once you're on ${plan}.` : 'Contact us if you need it.')}</p>
      <Button slot="secondary-actions" href={appUrl(route('app.settings.billing'), { from: feature })}>{plan ? `Upgrade to ${plan}` : 'See plans'}</Button>
    </Banner>
  );
}
