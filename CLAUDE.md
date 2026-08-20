# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel 13 API backend for a restaurant loyalty platform (multi-tenant SaaS, restaurants in the target market use FCFA/XOF). Mobile clients are served via a Flutter app (`fidelity_app`, sibling directory). Real-time delivery uses Laravel Reverb (self-hosted, Pusher-protocol-compatible), with Firebase Cloud Messaging (FCM) as a push fallback.

## Commands

```bash
composer setup        # install deps, copy .env, generate key, migrate, build assets
composer dev           # run server + queue worker + pail (logs) + vite concurrently
php artisan serve      # API only, http://localhost:8000

composer test          # clears config cache, then runs php artisan test
php artisan test                                   # same as above, no config clear
php artisan test --filter=testMethodName            # single test
php artisan test tests/Feature/SomeTest.php          # single file

vendor/bin/pint        # code style (Laravel Pint)
npm run dev             # vite dev server (assets)
npm run build            # vite production build
```

Tests run against in-memory SQLite (see `phpunit.xml`), not the Postgres dev database — no local Postgres setup is needed to run the suite.

## Local infra

`docker-compose.yml` provides Postgres (with **PostGIS**, for geo features like `client_restaurant_geo_optins`) and pgAdmin. Dev DB connection is `pgsql` (see `.env`, `DB_DATABASE=restaurant_loyalty`). Bring it up with `docker compose up -d` before running `artisan migrate` outside of tests.

Run `php artisan storage:link` once per environment so files on the `public` disk (e.g. client avatars under `storage/app/public/avatars`) are actually served — without it, uploads succeed (200 response, valid `avatar_url`) but the URL 404s because `public/storage` isn't symlinked yet.

Reverb runs as a separate process (started by `composer dev`) on `REVERB_SERVER_PORT` (default 8080); it must be running for presence-channel checks (`PresenceChecker`) and reward broadcasts to work locally.

`composer dev` also starts an `nginx` dev proxy (`docker/nginx-dev/nginx-dev.conf`) on port 8000, routing `/app/*` to Reverb (8080) and everything else to `artisan serve` (moved to 8001, internal-only). Port 8000 is the only one meant to be reached from outside (LAN IP or the ngrok tunnel in the Flutter app's `scripts/dev.sh`) — a free ngrok tunnel only forwards one port, so Reverb's websocket has to ride the same port as the API rather than exposing 8080 separately. Don't run `artisan serve --port=8000` directly alongside this proxy (port clash); use `composer dev` or, for a Reverb-less API-only session, `artisan serve` alone on its default port.

## Architecture: two coexisting domains

The codebase is mid-migration from an early single-tenant prototype to a multi-tenant platform. Both still exist side by side — know which one you're touching:

- **Legacy/prototype domain** — `User` model (Laravel default auth), `LoyaltyCard` (currently still keyed by `user_id` in the Eloquent model), `Reward`, stamp-based loyalty via `StampAdded` → `CheckRewardUnlock` listener → `RewardUnlocked` event. Routes live loose at the bottom of `routes/api.php` (`/customers/{customer}`, `/simulate`, `/device-tokens`, etc.), largely unprotected or used for demo/testing purposes (see comments in `routes/api.php`).
- **Multi-tenant domain (in progress)** — `Restaurant` → `StaffUser` (owner/manager/staff), `SuperAdmin`, `LoyaltyProgram` (per-restaurant, type: stamp/points/cashback/vip), `LoyaltyCard` (per the newer migration: `client_id` + `restaurant_id` + `loyalty_program_id`, QR/card codes, cashback balance, VIP tier), `LoyaltyTransaction` (append-only, never cascade-deletes — kept for anti-fraud history even if the card is removed), `Client` (mobile end-user auth, Sanctum), `RestaurantSubscription` + `SubscriptionPlan` + `PaymentTransaction` (FedaPay billing).

**Known inconsistency**: the `LoyaltyCard` Eloquent model (`app/Models/LoyaltyCard.php`) still reflects the old `user_id` schema, but its migration (`2026_07_20_000008_...`) drops and recreates the table with `client_id`/`restaurant_id`/`loyalty_program_id` and no `user_id` column. Don't assume the model's `fillable`/relations match the current table — check the migration first when working on loyalty cards.

Migrations dated `2026_07_20_*` largely use `Schema::dropIfExists` + recreate rather than incremental `Schema::table` alters — this is a still-evolving schema, not production data to preserve.

## Auth

Two separate guards/providers (`config/auth.php`): `users` (legacy `User` model) and `clients` (`Client` model, mobile app). All new mobile-facing auth work goes through `ClientAuthController` + Sanctum bearer tokens, not the legacy `AuthController`/`users` guard (kept only for backward compatibility per the comment in `routes/api.php`).

`ClientAuthController` supports phone+password auth, Google/Apple OAuth (via `SocialAuthService`, which validates ID tokens server-side against Google's SDK / Apple's JWKS), and a two-step OAuth flow: `socialLogin` creates a partial profile and returns `needs_profile_completion: true` when phone/name are missing, then `completeSocialProfile` (authenticated) fills them in.

Registration and profile updates involving a phone number run through `FraudRiskService` (`app/Services/Fraud/`), which scores risk (0-100) based on technical phone validity (`PhoneValidator`), duplicate-number detection, and country rules (`CountryRules`), and throws a `ValidationException` on `high_risk`. This is wired into `RegisterRequest::after()` — new FormRequests that accept a phone number should follow the same pattern (normalize via `PhoneParser` in `prepareForValidation()`, then call `FraudRiskService` in `after()`).

## Real-time rewards flow

`NotificationDispatcher::dispatchRewardUnlocked()` is the canonical way to notify a customer of an unlocked reward:
1. Broadcasts `RewardUnlocked` immediately on the client's presence channel via Reverb (cheap, fire-and-forget).
2. Checks `PresenceChecker::isCustomerOnline()` (queries Reverb's Pusher-compatible REST API for active presence-channel sockets) to pick a fallback delay — short if the client appears offline, longer if online.
3. Queues `SendRewardFcmFallback` with that delay as a backstop push notification. The client's `ack` endpoint (`RewardAckController`) is the only real proof of receipt — the presence check is just a timing optimization, not a guarantee.

`FcmService` sends via the HTTP v1 FCM API using a cached (3500s) OAuth access token generated from a service account (`storage/app/firebase/service-account.json` — not checked in). Dead device tokens (404/`UNREGISTERED`) are deleted automatically on send failure.

## Payments (FedaPay)

`PaymentController::initSubscriptionPayment` creates a `pending` `RestaurantSubscription` + `PaymentTransaction` before redirecting to FedaPay's hosted payment page; the subscription is only flipped to `active` by `FedaPayWebhookController` on a verified `transaction.approved` webhook (signature checked via `FedaPay\Webhook::constructEvent`, secret in `config('fedapay.webhook_secret')`). Webhook handling is idempotent (checks current status before reprocessing). Never trust client-side payment confirmation — activation only happens from the webhook.

## Phone handling

`app/Services/Phone/` (`PhoneParser`, `PhoneValidator`, `CountryRules`) wraps `propaganistas/laravel-phone` with app-specific normalization and country logic (e.g. an African-market bias in `FraudRiskService`'s scoring). Use `PhoneParser::normalize()` before storing or comparing phone numbers anywhere in the app.

<!-- code-review-graph MCP tools -->
## MCP Tools: code-review-graph

**IMPORTANT: This project has a knowledge graph. ALWAYS use the
code-review-graph MCP tools BEFORE using Grep/Glob/Read to explore
the codebase.** The graph is faster, cheaper (fewer tokens), and gives
you structural context (callers, dependents, test coverage) that file
scanning cannot.

### When to use graph tools FIRST

- **Exploring code**: `semantic_search_nodes_tool` or `query_graph_tool` instead of Grep
- **Understanding impact**: `get_impact_radius_tool` instead of manually tracing imports
- **Code review**: `detect_changes_tool` + `get_review_context_tool` instead of reading entire files
- **Finding relationships**: `query_graph_tool` with callers_of/callees_of/imports_of/tests_for
- **Architecture questions**: `get_architecture_overview_tool` + `list_communities_tool`

Fall back to Grep/Glob/Read **only** when the graph doesn't cover what you need.

### Key Tools

| Tool | Use when |
| ------ | ---------- |
| `detect_changes_tool` | Reviewing code changes — gives risk-scored analysis |
| `get_review_context_tool` | Need source snippets for review — token-efficient |
| `get_impact_radius_tool` | Understanding blast radius of a change |
| `get_affected_flows_tool` | Finding which execution paths are impacted |
| `query_graph_tool` | Tracing callers, callees, imports, tests, dependencies |
| `semantic_search_nodes_tool` | Finding functions/classes by name or keyword |
| `get_architecture_overview_tool` | Understanding high-level codebase structure |
| `refactor_tool` | Planning renames, finding dead code |

### Workflow

1. The graph auto-updates on file changes (via hooks).
2. Use `detect_changes_tool` for code review.
3. Use `get_affected_flows_tool` to understand impact.
4. Use `query_graph_tool` pattern="tests_for" to check coverage.
