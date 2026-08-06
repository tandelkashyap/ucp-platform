# UCP platform — data layer, connectors, control plane, cart/checkout, agent auth, and login (first slice)

Core database schema, three connector implementations (Shopify,
WooCommerce, BigCommerce), the control plane, agent-facing cart/checkout,
scoped agent credentials, and now the minimum needed for a frontend to
exist at all: register/login/logout.

## What's here

```
database/migrations/     11 migrations — agent_credentials added last pass
app/Models/
  AgentCredential.php     key_id/secret_hash generation + verification
  Merchant.php            agentCredentials()
app/Contracts/            CommerceConnector.php
app/Services/
  ConnectorManager.php
  Connectors/              Shopify, WooCommerce, BigCommerce
app/Jobs/
  SyncMerchantCatalog.php, UpsertSyncedProduct.php, RecordOrderEvent.php,
  TestStoreConnection.php
app/Http/
  Middleware/
    AuthenticateAgent.php  Verifies the Authorization header + scope
  Controllers/
    Controller.php
    AuthController.php  New — minimal register/login/logout, see note below
    MerchantController.php, StoreConnectionController.php,
    CapabilityConfigController.php
    AgentCredentialController.php  Issue/list/revoke credentials
    Ucp/
      Concerns/AuthorizesAgentCredential.php  Merchant-match half
      CatalogController.php    Credential-gated
      CartController.php       Credential-gated
      CheckoutController.php   Credential-gated
  Requests/
    ConnectStoreRequest.php
app/Policies/MerchantPolicy.php
app/Observers/MerchantObserver.php
app/Providers/
  ConnectorServiceProvider.php, MerchantServiceProvider.php
routes/api.php              register/login/logout added this pass
config/ucp.php
composer.json
```

## Why this piece, next

Everything before this pass built the machinery — three connectors with
working `createCart`/`updateCart`/`checkout` methods, a control plane for
merchants to plug in credentials — but the only UCP-facing endpoint that
actually existed was `CatalogController`. An agent could browse a
merchant's products and do nothing else. `CartController` and
`CheckoutController` are what make this a *commerce* protocol rather than
a read-only product feed: they're the first agent-facing code that calls
`createCart()`, `updateCart()`, and `checkout()` at all.

One nice validation from wiring this up: `CheckoutController` reuses
`RecordOrderEvent::dispatchSync()` — the exact same job the webhook
handlers and bulk catalog sync already dispatch into, now called
synchronously from a third, different context (a live HTTP request, not a
queue worker). No new order-writing logic needed to exist.

## How agent auth actually works

Before this pass, `carts` and `checkout` were public routes gated only by
`capability_configs` and a rate limit — anyone who could reach them could
create carts and attempt checkouts against a connected store, no identity
required. That's the gap this closes.

A merchant issues a credential via `POST merchants/{id}/agent-credentials`
with an `agent_platform` label and a list of scopes (`catalog`, `cart`,
`checkout` — only the ones with a real enforcement point exist as
options). The response is the only time the plaintext token
(`{key_id}.{secret}`) is ever visible — only `secret_hash` (SHA-256, not
bcrypt; see the migration comment for why) is persisted, so there's no
"view it again" feature to accidentally need.

Every UCP route now carries `agent.auth:{scope}` — `AuthenticateAgent`
checks the `Authorization: Bearer` header against `agent_credentials` and
confirms the credential has that scope, before the controller runs at all.
The one thing it deliberately doesn't check is whether the credential
belongs to the specific merchant in the URL — that's
`AuthorizesAgentCredential::assertCredentialMatches()`, called at the top
of each controller instead, consistent with how these controllers already
scope everything else (e.g. `CartController` checking `cart->merchant_id`)
at the controller level rather than splitting the logic across middleware
and controller.

Net effect on `CheckoutController`, concretely: a request now needs (1) a
capability turned on, (2) a valid, unexpired, non-revoked credential, (3)
that credential scoped for `checkout`, and (4) that credential's
`merchant_id` matching the URL — four independent checks, any one of which
being false is a 401/403/404, before a payment token ever reaches a
connector.

## Gaps this pass didn't close

- **Per-credential rate limiting.** `throttle:120,1` is still IP-based, not
  credential-based — a compromised-but-valid credential isn't currently
  throttled any differently than normal traffic.
- **Key rotation UX.** Revoking (`DELETE .../agent-credentials/{id}`) works;
  there's no "rotate without downtime" (issue a second active credential,
  give the caller a window to switch, then revoke the first) flow yet.
- Everything already listed as a gap before this pass — line-item removal,
  the `202` checkout path not resolving itself, the illustrative header
  names — is still open, unchanged by this pass.

## Two planes, one routes file

`routes/api.php` splits into exactly the two groups from the original
architecture diagram, and it's worth reading as confirmation that split
was real and not just a diagram: `ucp/{merchant}/catalog` is public,
gated by `Merchant::hasCapability()`, meant for agents. Everything under
`merchants/{merchant}/...` requires `auth:sanctum` and a `MerchantPolicy`
check, meant for the humans running the store. Nothing overlaps.

## The actual "plug in credentials" flow

`ConnectStoreRequest` validates different fields per platform — literally
transcribed from the credential shape each connector's own doc-block
already specified (`shop_domain`/`access_token` for Shopify, three fields
for WooCommerce, `store_hash`/`client_id`/`access_token` for BigCommerce).
`StoreConnectionController::store()` saves it and dispatches
`TestStoreConnection`, which makes one real, cheap call through the
connector (`getCatalog(['limit' => 1])`) before marking the connection
`connected` — a typo'd token shows up as a visible error immediately
instead of silently breaking the first real sync. On success it kicks off
`SyncMerchantCatalog` to do the full pull.

## One thing worth knowing about Laravel 11 specifically

`app/Http/Controllers/Controller.php` is included this pass and shouldn't
be skipped: Laravel 11's slimmed-down default skeleton no longer includes
`AuthorizesRequests` on the base controller the way 10 and earlier did.
Every `$this->authorize(...)` call in these controllers depends on that
trait being there — worth knowing if you ever regenerate this file from a
fresh `laravel new` and wonder why authorization silently stops working.

## Registration steps

Both providers and the middleware alias — see "Getting this running
locally" at the bottom of this file, which is now the single canonical
setup path (fresh install via `php artisan install:api`, not the manual
Sanctum steps this section used to describe).

## Deliberately not built in this pass

- **Real auth.** `AuthController` issues and revokes Sanctum tokens and
  nothing else — no email verification, password reset, or 2FA. Swap it
  for Fortify or Breeze when that work happens rather than hand-building
  those pieces; this exists only so a frontend has something to call.
- **Billing (Cashier/Stripe).** Merchants can sign up and connect stores
  with no payment step yet.
- **The `identity_linking` capability's actual OAuth 2.0 server** (Passport)
  — `capability_configs` has a row for it and it can be toggled, but there's
  no `/.well-known/oauth-authorization-server` yet for a platform to
  actually use it.
- **A UI.** The frontend decision is made now, though: Next.js, separate
  from Laravel, not Inertia — the control-plane API already returns JSON
  with Sanctum bearer auth rather than `Inertia::render()` page props, so
  Next.js can consume what's already built as-is. Switching to Inertia at
  this point would mean reworking every controller.

## What each connector revealed

**WooCommerce** validated the interface as-is — every difference (session
cart tokens, per-line item keys, plugin-specific payment gateways, two
different money representations on one platform) fit inside the existing
seven methods without changing the contract. It also caught a real bug:
`ShopifyConnector.handleWebhook()` referenced two job classes that were
never created. Both connectors now dispatch normalized data into shared
`UpsertSyncedProduct`/`RecordOrderEvent` jobs instead of one pair of jobs
per platform.

**BigCommerce** is where the interface actually had to change. Its
checkout flow inverts the other two: an unpaid Order is created first, then
payment is captured against it as a separate call to a different host
(`payments.bigcommerce.com`) using a token scoped to that one order —
rather than "submit payment, get an order back." Getting there requires a
billing address and shipping consignments as mandatory sub-resources of
the Checkout, which exposed a real gap: `checkout()` had nowhere to put
address data. Fixed by adding `array $shippingAddress = []` as a third,
optional parameter — Shopify's and WooCommerce's implementations both
still work (their real-world equivalents need an address too, they're just
not fully wired in this slice), and BigCommerce's is the one where the API
makes skipping it impossible.

Also worth remembering: BigCommerce webhooks put the event type in the
JSON body (`scope`), not a header, and only send a thin pointer — resource
type and id — not the resource itself, so handling one costs an extra API
call other platforms don't need. And every connector built so far has its
own money-representation gotcha: Shopify is decimal strings; WooCommerce is
decimal strings on one API and pre-multiplied integers on another; V3
BigCommerce returns a JSON number. None of these are interchangeable —
worth double-checking on any new connector rather than assuming.

## What was actually verified here

This sandbox can't reach Packagist (only npm/pip/crates/GitHub/Ubuntu
mirrors are allowed), so `composer install` isn't possible in this
environment, and there's no way to boot a real Laravel app or run these
migrations against a database from here.

What **was** checked: every file above was run through `php -l` (PHP 8.3),
so they're syntactically valid PHP and the Laravel APIs used (Schema
builder, Eloquent casts/relationships, the HTTP client, queued jobs) are
used the way current Laravel actually expects. What wasn't checked: this
hasn't executed against a live database, and none of the three connectors
has run against an actual store. Treat the API call shapes as solid,
realistic starting points rather than as tested code — run each against a
dev store and fix up field/endpoint names as needed before trusting any of
them with real orders. `BigCommerceConnector`'s payment-capture step
(the order-scoped token, the separate `payments.bigcommerce.com` host) is
the single least certain piece of this whole slice — verify it against
BigCommerce's current docs before building on top of it, more so than
anything else here.

## Getting this running locally (fresh install)

```bash
composer create-project laravel/laravel ucp-platform
cd ucp-platform

# Installs Sanctum, creates routes/api.php, and wires it into
# bootstrap/app.php in one step — the Laravel 11+ way to do this, cleaner
# than the manual composer require + publish this README used to describe.
php artisan install:api

composer require guzzlehttp/guzzle
```

Now copy `app/`, `database/migrations/`, `database/seeders/DemoSeeder.php`, and
`config/ucp.php` from this zip into the project, **replacing** the
`routes/api.php` that `install:api` generated with this zip's version.

Register the second provider and the agent-auth middleware alias — `install:api`
already added the `api:` routing line and `ConnectorServiceProvider`
registration is assumed done already if you're following along from an
earlier pass:

```php
// bootstrap/providers.php
App\Providers\MerchantServiceProvider::class,

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias(['agent.auth' => \App\Http\Middleware\AuthenticateAgent::class]);
})
```

Point `.env` at a real database (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
— for Laragon specifically, that's typically `root` with no password
against a database you create yourself first, e.g. via Laragon's Database
button or `mysql -u root -e "CREATE DATABASE ucp_platform"`), then:

```bash
php artisan migrate
php artisan db:seed --class=DemoSeeder
```

The seeder prints a demo user, a merchant with a `connected` store and two
products, and a one-time agent token — copy that token immediately, it's
not retrievable again. If Laragon doesn't pick up the project
automatically, check Laragon's "www" list points at this folder and
reload.

## One design decision worth flagging

`ShopifyConnector` calls both Shopify's Admin API (catalog/orders) and
Storefront/Cart API (cart/checkout) through one `graphql()` helper for
readability. Real Shopify apps authenticate these two APIs separately —
your `store_connections.credentials` will need both an Admin API access
token and a Storefront API token. Worth splitting into two internal HTTP
clients once this connector has more than a couple of methods; noted
inline in the file too.

## Natural next steps

- The Next.js dashboard itself — login/register screens, then the
  store-connection and agent-credential flows, since those are the pieces
  already fully built on the backend
- Per-credential rate limiting and a key-rotation flow
- Line-item removal in `CartController::update()`
- Real auth (Fortify/Breeze) and billing (Cashier), in place of
  `AuthController`'s minimal version
- Passport, for the `identity_linking` capability's OAuth 2.0 server
- `audit_log` table
- Factories/seeders + tests
- Shopify's and WooCommerce's `checkout()` still don't use
  `$shippingAddress` for real — unchanged by this pass
