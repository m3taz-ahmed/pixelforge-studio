# Pixelforge Studio

AI image studio: authenticated users generate images through the [Pixelforge](https://pixelforge.promptedit.dev/docs) provider API, pay provider cost + 25% markup, and see live status, honest estimates, and full billing history.

Laravel 12 · Vue 3 · Inertia v2 · Tailwind · PostgreSQL · brick/math for exact money.

## Requirements

- PHP 8.4+, Composer, Node 20+, PostgreSQL (SQLite works for tests)

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# configure DB_* and PIXELFORGE_* in .env (below)
php artisan migrate
npm run build        # or: npm run dev
php artisan serve
php artisan queue:work
```

### Environment variables

| Var | Purpose |
|---|---|
| `DB_*` | PostgreSQL connection (any database Laravel supports works) |
| `PIXELFORGE_API_KEY` | Pixelforge key (`pf_…`) — one key per environment |
| `PIXELFORGE_WEBHOOK_SECRET` | The key's `whsec_…` webhook signing secret |
| `PIXELFORGE_BASE_URL` | Default `https://pixelforge.promptedit.dev/v1` |
| `QUEUE_CONNECTION` | `database` recommended — webhook processing is queued |

Optional operator tasks:

```bash
php artisan generations:reconcile   # poll open generations (missed-webhook fallback)
php artisan user:make-admin you@example.com
```

Schedule `generations:reconcile` (and `queue:work`) via cron/scheduler in production.

## How it works

### Money (exact, never floats)

- All money is a **decimal string with 9 places** (`DECIMAL(12,9)` columns); every operation uses `brick/math` `BigDecimal` — no float ever touches a price.
- **Provider cost** = Σ (`token_count × rate ÷ 1_000_000`) over `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` — using the `rates` object **frozen on the generation** at submit time. Later price changes can never rewrite history.
- **User charge** = `provider_cost × 1.25` (documented `Pricing::MARKUP`).
- Billing is **exactly-once**: `GenerationSync::settle()` runs in a transaction that (a) claims the generation with a conditional `succeeded`-only update, and (b) inserts one `charge` row in `credit_transactions` with a unique index on `generation_id` — duplicate webhook deliveries hit the unique constraint and do nothing. Balance + ledger stay consistent under retries.
- **Failed generations are never charged** — settlement only runs on `succeeded`.

### Price estimates (the heuristic)

Token usage is only knowable after completion, so the pre-submit price is a documented heuristic (`Pricing::estimate`):

- **Output tokens**: taken exactly from `GET /v1/pricing` metadata — fixed per size+quality.
- **Input text**: `ceil(chars/4)` ±15% variance (per provider docs).
- **Input images**: 765 tokens per reference image (exact, per docs).
- **Reasoning**: quality-bounded min/max range; the estimate shows the **midpoint**, and the UI also shows the **worst-case** (`max`), which is what the credit check reserves against.

The token model + rates come from `/v1/pricing` (cached 5 min), with a local fallback copy so estimates still work if the endpoint is down.

### Submission pipeline

1. Local `Generation` row is created **before** calling Pixelforge (status `pending`), with our UUID in `metadata.local_id` and a persisted `Idempotency-Key`. A webhook that beats the HTTP response can still match the row.
2. `PixelforgeClient` sends the create with `Idempotency-Key`; retries honor `Retry-After` exactly, then exponential backoff, capped. 4xx rejections (`[blocked]`, validation) mark the row `failed` immediately — provider never billed, user never charged.
3. Network-level failure mid-submit leaves the row `pending`; the reconcile job replays the same payload + idempotency key — the provider replays instead of double-creating.

### Webhooks

`POST /webhooks/pixelforge` (`PixelforgeWebhookController`):

- HMAC-SHA256 over `"<t>.<raw body>"` verified **before** JSON decode, `hash_equals`, timestamp window ±300s. Outside CSRF middleware.
- Returns `204` fast; the real work is queued (`ProcessPixelforgeWebhook`).
- **Dedupe by event id**: `webhook_events.event_id` unique — the insert is the dedupe; a duplicate delivery is a no-op.
- **State machine** (`GenerationSync`): `pending → queued → processing → succeeded|failed`; terminal states never regress, so out-of-order deliveries (succeeded arriving before processing) settle correctly.
- **Early delivery**: matching falls back to `metadata.local_id` when `provider_id` isn't stored yet.
- **Fallback**: `generations:reconcile` polls every open generation via `GET /v1/generations/{id}` and feeds it through the same sync — missed webhooks converge identically.

### Stretch features

- **Credits**: balance on the user, fake top-ups, generation blocked when `balance < estimate.max` — all ledger writes row-locked so `balance_after` always agrees with `users.balance`.
- **Admin report** (`/admin/usage-report`, `admin` middleware + `php artisan user:make-admin`): per-day and per-user credit spend vs provider cost, reconciled against `GET /v1/usage` with an explicit delta column.

## Tests

```bash
php artisan test              # SQLite in-memory, sync queue
```

Coverage centers on the graded paths: exact cost math, frozen rates, markup, estimates, HMAC signature acceptance/rejection (incl. stale timestamps), event dedupe, out-of-order + early deliveries, exactly-once billing, free failures, idempotent submit, `Retry-After` behavior, and the insufficient-balance guard.

Live-tested against the real Pixelforge API: `[succeed]`, `[fail]`, `[blocked]`, `[overloaded]`, `[duplicate]` (double deliveries dedupe to one charge).

## Deployment notes

**Live demo:** https://refactored-train-j49qjx494rjh59jv-8000.app.github.dev

Deployed on a GitHub Codespace (2-core, SQLite): `php artisan serve` (with `PHP_CLI_SERVER_WORKERS`), a `queue:work` daemon, and `schedule:work` which runs `generations:reconcile` every minute. The Pixelforge *production* key's webhook URL points at `/webhooks/pixelforge`. A scheduled GitHub Action pings `/up` every 10 minutes to keep the demo awake during the review window.

The repo also ships a `Dockerfile` + `docker/start.sh` for a single-container deploy (Apache + worker + scheduler, `migrate --force` on boot, binds `$PORT`) — drop it on any container host with `DB_*` env vars pointed at Postgres and it works unchanged.
