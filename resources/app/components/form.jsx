// Forms for the React admin: state, server validation errors and submit through the router.
import { useCallback, useRef, useState } from 'react';
import { useRouter } from '../router.jsx';

const get = (obj, path) => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
const set = (obj, path, value) => {
  const keys = path.split('.');
  const out = Array.isArray(obj) ? [...obj] : { ...obj };
  let cur = out;
  keys.slice(0, -1).forEach((k, i) => {
    const next = cur[k];
    cur[k] = Array.isArray(next) ? [...next] : next && typeof next === 'object' ? { ...next } : (/^\d+$/.test(keys[i + 1]) ? [] : {});
    cur = cur[k];
  });
  cur[keys[keys.length - 1]] = value;
  return out;
};

/**
 * const form = useForm({ name: '' });
 * <input {...form.bind('name')} />  ...  form.post('/app/x')
 */
export function useForm(initial = {}) {
  const { submit } = useRouter();
  const [data, setData] = useState(initial);
  const [errors, setErrors] = useState({});
  const [processing, setProcessing] = useState(false);
  const dataRef = useRef(data);
  dataRef.current = data;

  const setValue = useCallback((path, value) => setData((d) => set(d, path, value)), []);

  const bind = (path, { type } = {}) => {
    const value = get(data, path);
    if (type === 'checkbox') {
      return { name: path, checked: !!value, onChange: (e) => setValue(path, e.target.checked) };
    }
    return { name: path, value: value ?? '', onChange: (e) => setValue(path, e.target.value), 'aria-invalid': errors[path] ? true : undefined };
  };

  const busy = useRef(false);
  const send = async (url, { method = 'POST', data: override, confirm } = {}) => {
    if (busy.current) return { ok: false, cancelled: true };
    busy.current = true;
    setProcessing(true);
    const result = await submit(url, override ?? dataRef.current, { method, confirm });
    busy.current = false;
    setProcessing(false);
    if (!result.cancelled) setErrors(result.errors || {});
    return result;
  };

  return { data, setData, set: setValue, bind, errors, setErrors, processing, post: (url, opts) => send(url, opts), send, reset: () => setData(initial) };
}

/** Label, control, help text and error. */
export function Field({ label, help, error, children, className = '' }) {
  return (
    <label className={`ui-field ${className}`}>
      {label && <span className="ui-label">{label}</span>}
      {children}
      {error ? <span className="ui-error">{error}</span> : help ? <span className="ui-help">{help}</span> : null}
    </label>
  );
}

/** A checkbox row with a label and optional help. */
export function Check({ label, help, ...props }) {
  return (
    <label className="ui-check">
      <input type="checkbox" {...props} />
      <span>
        {label}
        {help && <small>{help}</small>}
      </span>
    </label>
  );
}

/** A <form> that submits through the router (Polaris submit buttons and Enter submit it natively). */
export function Form({ onSubmit, children, className }) {
  return (
    <form
      className={className}
      noValidate
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit?.(e);
      }}
    >
      {children}
    </form>
  );
}

/** A one-click action (POST) rendered as a Polaris button. */
export function ActionButton({ url, data, confirm, children, method = 'POST', ...props }) {
  const { submit } = useRouter();
  const [busy, setBusy] = useState(false);
  return (
    <s-button
      {...props}
      loading={busy || undefined}
      onClick={async () => {
        setBusy(true);
        await submit(url, data || {}, { method, confirm });
        setBusy(false);
      }}
    >
      {children}
    </s-button>
  );
}
