// JSON endpoints for data a page loads on its own (App Bridge adds the session token to fetch).
import { appUrl } from './router.jsx';

export async function api(path, { method = 'GET', body } = {}) {
  const res = await fetch(appUrl('/app/api/' + path.replace(/^\//, '')), {
    method,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const error = new Error(data.message || 'Something went wrong. Try again.');
    error.status = res.status;
    error.data = data;
    throw error;
  }
  return data;
}
