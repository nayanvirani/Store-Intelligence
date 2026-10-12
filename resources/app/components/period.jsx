import { useState } from 'react';
import { appUrl, useRouter, useUrl, withQuery } from '../router.jsx';
import { Button } from './ui.jsx';

const SHORT = { 7: '7 days', 30: '30 days', 90: '90 days', mtd: 'This month', last_month: 'Last month' };

/** Date presets and a custom range (Final MVP F12). Free plans see only the last 30 days. */
export function PeriodPicker({ period }) {
  const url = useUrl();
  const { visit } = useRouter();
  const [from, setFrom] = useState(period.from);
  const [to, setTo] = useState(period.to);
  const [custom, setCustom] = useState(period.key === 'custom');
  const presets = Object.entries(period.presets).filter(([k]) => k !== 'custom');
  if (period.locked) return null;
  const today = new Date().toISOString().slice(0, 10);

  return (
    <div className="period">
      <nav className="seg" aria-label="Date range">
        {presets.map(([key, label]) => (
          <a key={key} aria-selected={period.key === key && !custom} href={appUrl(withQuery(url, { range: key, from: null, to: null, page: null }))} onClick={() => setCustom(false)}>{SHORT[key] || label}</a>
        ))}
        <a href="#custom" aria-selected={custom || period.key === 'custom'} onClick={(e) => { e.preventDefault(); setCustom(true); }}>Custom</a>
      </nav>
      {custom && (
        <form className="period-custom" onSubmit={(e) => { e.preventDefault(); visit(withQuery(url, { range: 'custom', from, to, page: null })); }}>
          <input type="date" value={from} min={period.min || undefined} max={to || today} onChange={(e) => setFrom(e.target.value)} aria-label="From" />
          <span className="muted">to</span>
          <input type="date" value={to} min={from} max={today} onChange={(e) => setTo(e.target.value)} aria-label="To" />
          <Button type="submit" size="slim" variant="primary">Apply</Button>
        </form>
      )}
    </div>
  );
}

/** "↑ 12%" against the previous period; tone flips for costs (lower is better). */
export function Delta({ now, before, inverse = false }) {
  if (before === null || before === undefined || now === null || now === undefined || Number(before) === 0) return <span className="muted">—</span>;
  const pct = ((now - before) / Math.abs(before)) * 100;
  if (!Number.isFinite(pct)) return <span className="muted">—</span>;
  const dir = pct > 0.5 ? 'up' : pct < -0.5 ? 'down' : 'flat';
  const good = dir === 'flat' ? null : (dir === 'up') !== inverse;
  return <span className={good === null ? 'muted' : good ? 'up' : 'down'}>{dir === 'up' ? '↑' : dir === 'down' ? '↓' : '→'} {Math.abs(Math.round(pct))}%</span>;
}

/** A term with its definition on hover and focus (every figure explains itself). */
export function Term({ children, help }) {
  if (!help) return children;
  return <span className="term" tabIndex={0} title={help} aria-label={`${typeof children === 'string' ? children : ''}: ${help}`}>{children}<i aria-hidden="true">?</i></span>;
}
