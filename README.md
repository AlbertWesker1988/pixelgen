# PixelGen

A Laravel + Vue + Inertia app for the Pixelforge take-home. Users sign in, write a
prompt, and generate images through the Pixelforge API. The app shows an estimated
price before submitting, then records the **exact** cost each generation actually
incurred (Pixelforge's cost plus a 25% markup), settling it from webhooks with a
polling fallback.

## Stack

- PHP 8.3+, Laravel 13
- Vue 3 + Inertia + Tailwind (Laravel Breeze)
- SQLite (self-contained; swap `DB_*` for Postgres/MySQL if you prefer)
- bcmath for exact money maths

## Requirements

- PHP 8.3+ with `bcmath`, `pdo_sqlite`, `curl`
- Composer
- Node 18+ and npm

## Setup

```bash
git clone <repo-url> pixelgen
cd pixelgen

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate
```

## Pixelforge configuration — where to put your keys

Everything secret lives in `.env` (which is git-ignored). `.env.example` only lists
the variable names, so fill in your own values:

1. Create an account at <https://pixelforge.promptedit.dev/register>.
2. Create an **API key** (use one key per environment) and put it in `.env`:
   ```
   PIXELFORGE_API_KEY=pf_your_key_here
   ```
3. Open the **Webhooks** page, copy the **signing secret** into `.env`:
   ```
   PIXELFORGE_WEBHOOK_SECRET=whsec_your_secret_here
   ```
4. Set that key's **Endpoint URL** to `https://<your-app-url>/webhooks/pixelforge`.
   Pixelforge cannot reach `localhost`, so for local development either use a tunnel
   (Expose, ngrok, Cloudflare Tunnel) and set the tunnel URL, or skip webhooks and use
   the polling fallback (below).

| Variable | Meaning |
|---|---|
| `PIXELFORGE_BASE_URL` | API base URL (a sensible default is already set) |
| `PIXELFORGE_API_KEY` | your API key, sent as a Bearer token |
| `PIXELFORGE_WEBHOOK_SECRET` | the key's signing secret (`whsec_...`) |
| `PIXELFORGE_MARKUP` | markup over provider cost as a decimal, `0.25` = 25% |

## Running

One command runs the dev server, the queue worker, Vite and the log viewer together:

```bash
composer run dev
```

Open <http://127.0.0.1:8000>, register, and generate. Prompts containing `[fail]`,
`[slow]`, `[duplicate]`, `[blocked]` or `[overloaded]` exercise the error paths on
purpose.

Prefer to run the pieces yourself:

```bash
php artisan serve
php artisan queue:work   # processes the create + webhook jobs
npm run dev              # or: npm run build
```

### Webhooks vs polling

- With a public webhook URL configured, Pixelforge pushes every status change and the
  app settles generations in real time.
- Without one (local, no tunnel), run the polling fallback:
  ```bash
  php artisan schedule:work      # runs generations:poll every minute
  php artisan generations:poll   # or settle once, on demand
  ```

## Tests

```bash
php artisan test
```

The suite covers the money maths (checked against Pixelforge's own worked example),
webhook signature verification, idempotency, out-of-order handling, "failed is free",
and the polling fallback.

## How it works

- **Money** is computed with bcmath as exact decimal strings and stored as strings,
  never floats, so nine-plus-decimal amounts never drift.
- The **estimate** shown before submit is an upper bound (text +15%, reasoning at the
  quality maximum), so a user is never charged more than shown; the **actual** cost is
  recorded from the webhook's real token usage.
- **Create** runs in a queued job with an `Idempotency-Key`, so a retry after a 503
  never produces a duplicate generation.
- **Webhooks** are signature-verified (HMAC-SHA256 over `timestamp.rawBody`, compared
  in constant time, with a 5-minute tolerance), de-duplicated by a unique `event_id`,
  and applied so a generation never moves back from a final state — events can arrive
  twice or out of order.
- **Polling** (`generations:poll`) is the fallback for missed webhooks and reuses the
  same settle logic (`GenerationUpdater`).
- Generation **statuses** are a backed enum (`App\Enums\GenerationStatus`).

## Deployment notes

- Use a **separate Pixelforge key per environment**; point its webhook URL at the
  deployed domain and set its secret in the host's environment.
- Run a **queue worker** and the **scheduler** (for the polling fallback) on the host.
- With SQLite, deploy to a host with a **persistent disk**; otherwise point `DB_*` at
  a managed Postgres/MySQL.
