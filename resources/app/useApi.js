import { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';

/** Loads an endpoint and re-loads when `deps` change: { data, error, loading, reload }. */
export function useApi(path, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const load = useCallback(() => {
    let alive = true;
    setState((s) => ({ ...s, loading: true, error: null }));
    api(path).then(
      (data) => alive && setState({ data, error: null, loading: false }),
      (error) => alive && setState({ data: null, error, loading: false }),
    );
    return () => { alive = false; };
  }, [path]);
  useEffect(load, [load, ...deps]);
  return { ...state, reload: load };
}
