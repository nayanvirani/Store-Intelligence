# Store Intelligence

Profit, store protection and marketing intelligence for Shopify — *See your real profit. Catch problems before they cost you. Plan a month of marketing in minutes.*

Laravel app serving the public website, the Shopify embedded app and the internal super admin, deployed on Railway with PostgreSQL. Built on the same core as Growvia (auth, Managed Pricing billing, entitlements, internal admin).

## Structure

| Area | Where |
| --- | --- |
| Public website | `routes/web.php` → `SiteController`, `resources/views/site` |
| Embedded app | `/app/*` routes, `app/Http/Controllers/App`, React admin in `resources/app` (built to `public/spa`) |
| Super admin | `/admin/*`, `app/Http/Controllers/Admin`, `resources/views/admin` — stores, plans & features, limits, flags, sync health, failed jobs, support, messages, email, legal, finance, team |
| Shopify auth | `AuthenticateShopify`: App Bridge session tokens + token exchange (managed install) |
| Data sync | `app/Services/Sync` + `app/Jobs`: products, costs, inventory, orders and refunds; webhooks + hourly/daily reconciliation |
| Profit | `app/Services/Profit/ProfitCalculator.php`: the profit waterfall |
| Webhooks | `POST /webhooks/shopify` (HMAC-verified, idempotent via `webhook_receipts`) |
| Billing | Shopify Managed Pricing — Free / Starter / Growth / Pro, edited in the super admin |

## Deploy

Pushing to `main` deploys the Railway service (Dockerfile). The container migrates, then runs the queue worker, the scheduler and FrankenPHP. App config (URLs, scopes, webhooks): `shopify app deploy`.

Secrets are Railway variables only — see `.env.example` for the names.

## Frontend

```bash
npm install && npm run build:app   # builds resources/app into public/spa (committed)
```

## Tests

```bash
php artisan test
```
