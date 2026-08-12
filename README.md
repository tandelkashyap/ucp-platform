# UCP platform — data layer, connectors, control plane, cart/checkout, agent auth, and login (first slice)

Core database schema, three connector implementations (Shopify,
WooCommerce, BigCommerce), the control plane, agent-facing cart/checkout,
scoped agent credentials, and now the minimum needed for a frontend to
exist at all: register/login/logout.

## What's here

```
database/migrations/     13 migrations — magento enum + agent_credentials added recently
app/Models/
  AgentCredential.php     key_id/secret_hash generation + verification
  Merchant.php            agentCredentials(), show()
app/Contracts/            CommerceConnector.php
app/Services/
  ConnectorManager.php
  Connectors/              Shopify, WooCommerce, BigCommerce, Magento
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

**Magento** validated the interface again — no signature changes needed —
but is the most structurally different of the four so far: products are
keyed by SKU (a string) in most endpoints, not a numeric/GID id the way
Shopify, WooCommerce, and BigCommerce all do it. `getProduct(string
$externalId)` already took a string, so nothing had to change, but it's
worth knowing `$externalId` means something different depending which
connector you're reading.

More importantly, building this one exposed a gap that isn't specific to
Magento at all: **none of the four connectors actually have a webhook
*route* wired up.** `handleWebhook()` exists as an interface method on
every connector, but there's no `POST /webhooks/{platform}/{merchant}`
endpoint anywhere in `routes/api.php` that would receive a real inbound
webhook and call it. The only sync path that's ever actually run end to
end is `SyncMerchantCatalog`'s polling. Doesn't matter for Magento
specifically — core Open Source has no webhook system to receive from
anyway — but it means real-time sync doesn't work for *any* platform yet,
which is worth knowing before assuming Shopify/WooCommerce/BigCommerce are
further along than they are.

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

**Two real examples of that limitation, not just a hedge.** `php -l`
checks syntax — it can't catch anything that only breaks at class-load
time or at actual runtime, and this build has now hit both kinds:

`TestStoreConnection` originally named its constructor property
`$connection`, colliding with `Queueable`'s own internal `$connection`
property (the *queue* connection a job runs on — redis, database, sync —
a completely different thing). Trait composition conflicts are resolved
at class-load time; nothing short of actually instantiating the class was
ever going to catch it. Fixed by renaming to `$storeConnection`.

Finding that one prompted an audit of every model's `$fillable` against
its actual migration columns — and turned up two real, silent bugs from
the exact same failure mode: a column added after the model was first
written, with the column itself and the code writing to it both correct,
but never added to `$fillable`. Mass assignment doesn't error on this —
it just silently drops the field. `StoreConnection.last_error` had been
silently discarded by every `update()` call since the column was added
(exactly what produced the `NULL` you'd see querying it directly, even
after a real failure). `AgentCredential.last_used_at` was the same bug in
a second, quieter place — `AuthenticateAgent` calls `$credential->update(['last_used_at' => now()])`
on every single authenticated agent request, and that write has been
silently doing nothing since the credential system was built. Both are
fixed now (both fields added to their model's `$fillable`), but — same
caveat as the trait fix — confirmed correct by reading the code, not by
watching it actually write to a database here.

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

The seeder now creates **two** demo merchants — `demo-store` (fake
Shopify) and `demo-store-magento` (fake Magento) — each with a `connected`
store, two products, and its own one-time agent token printed to the
console. Copy both tokens immediately, neither is retrievable again. Note
it inserts that `connected` status directly for both; it doesn't go
through `TestStoreConnection` for either one, which is why the step below
wasn't obvious until connecting a real store. If you have a real local
Magento instance, connecting it for real through the dashboard (like this
project's own testing did) proves far more than the seeded Magento entry
ever will — the seeded one exists for quickly getting the dashboard back
into a demo-able state without needing any real store running at all, not
as a substitute for that.

**Run a queue worker, or nothing that dispatches a job will ever resolve.**
`TestStoreConnection`, `SyncMerchantCatalog`, `UpsertSyncedProduct`, and
`RecordOrderEvent`'s async dispatches are all queued jobs — connecting a
store through the dashboard (or the API directly) pushes `TestStoreConnection`
onto a queue and returns immediately with `status: connecting`. Nothing
processes that queue unless something is told to:

```bash
php artisan queue:work
```

Leave that running in its own terminal. For faster local iteration instead,
set `QUEUE_CONNECTION=sync` in `.env` — every queued job then runs inline,
immediately, no worker needed. Convenient for testing, wrong for
production (defeats the reason these are queued jobs in the first place —
an agent-facing request shouldn't block on a slow external API call).
Either way, a connection already stuck at `connecting` from before this
was running won't resolve itself; disconnect and reconnect it.

If Laragon doesn't pick up the project automatically, check Laragon's
"www" list points at this folder and reload.

## One design decision worth flagging

`ShopifyConnector` calls both Shopify's Admin API (catalog/orders) and
Storefront/Cart API (cart/checkout) through one `graphql()` helper for
readability. Real Shopify apps authenticate these two APIs separately —
your `store_connections.credentials` will need both an Admin API access
token and a Storefront API token. Worth splitting into two internal HTTP
clients once this connector has more than a couple of methods; noted
inline in the file too.

## Backend additions driven by the frontend, this pass

Building the Next.js per-merchant dashboard page surfaced two real gaps
here, same pattern as `GET /merchants` before it:

- `MerchantController::show()` + `GET merchants/{merchant:slug}` — there
  was a way to list a user's merchants and create one, but no way to fetch
  a single merchant's details. Bound by `:slug` specifically on this one
  route; every other `merchants/{merchant}/...` route still binds by id,
  which the frontend resolves once via this endpoint and reuses.
- `TestStoreConnection` now flips a `pending` merchant to `active` on its
  first successful connection. Nothing did this before — a merchant could
  be fully working (connected store, synced catalog, enabled capabilities)
  and still show `pending` forever, which the dashboard's status badge
  made obvious in a way a raw API response hadn't.

## Natural next steps

- **The missing webhook routes** — see above. `POST
  /webhooks/{platform}/{merchant}` per platform (or one generic route
  dispatching by platform), verifying each platform's own signature
  scheme before calling `handleWebhook()`
- **Magento timeouts.** 30s (Laravel's HTTP client default) wasn't enough
  for a local install — `MagentoConnector` now sets 60s explicitly. If
  that's still not enough, or if the request times out with *zero* bytes
  received rather than a slow-but-eventual response, that's more likely a
  connectivity/DNS issue specific to running from a CLI queue worker than
  Magento actually being slow — worth testing the identical request
  directly in Postman (same URL, same Bearer token) to isolate whether
  it's this code or the local environment. `verify_ssl` now has a checkbox
  in `ConnectStoreForm` (Magento only) — no need to set it via the API
  directly anymore.
- **Magento checkout needs an email address, and there was no field for
  it anywhere.** Real finding, not speculation: `payment-information` on a
  *guest* cart (the only kind `createCart()` ever makes) requires a
  top-level `email`, and `checkout()` never sent one at all — caught by an
  actual Magento 400 response (`"%fieldName" is required`, `fieldName:
  email`), not found by inspection. Fixed by reading `email` out of
  `shipping_address` (a real design seam — address and buyer-email are
  different concerns sharing one object — not a clean fix) and failing
  early with a clear message if it's missing, rather than letting
  Magento's generic error surface after a round trip. Include `email` in
  `shipping_address` on every Magento checkout call going forward.
- **`checkout()` was lying about order status.** It returned `'confirmed'`
  for any request that got an order ID back, regardless of Magento's
  actual order state. First real order placed came back `'confirmed'`
  from this code while Magento's own admin correctly showed it
  `Pending` — `checkmo` and other offline payment methods don't
  auto-capture, so `Pending` was Magento being right, not slow. Fixed:
  `checkout()` now calls `getOrderStatus()` after a successful order
  creation instead of guessing, so both paths run through the same
  `mapOrderStatus()` and can't silently disagree with each other again.
- **Still open, not yet root-caused**: the `shipping-information` call
  that timed out earlier this session eventually succeeded, but the
  request that finally worked took ~60 seconds — suspiciously close to
  the timeout ceiling rather than comfortably under it. Likely still the
  same underlying slowness, just finishing just in time rather than being
  fixed. Worth checking Magento's enabled shipping methods (Stores →
  Configuration → Sales → Shipping Methods) for a live-carrier method
  with no real credentials configured, which is the kind of thing that
  hangs rather than fails cleanly. A response this slow would likely
  time out against a real agent orchestrator's own HTTP client even
  though Magento eventually succeeds server-side — worth resolving before
  trusting this path with anything beyond local testing.
- Per-credential rate limiting and a key-rotation flow
- Line-item removal in `CartController::update()`
- Real auth (Fortify/Breeze) and billing (Cashier), in place of
  `AuthController`'s minimal version
- Passport, for the `identity_linking` capability's OAuth 2.0 server
- `audit_log` table
- Factories/seeders + tests
- Shopify's and WooCommerce's `checkout()` still don't use
  `$shippingAddress` for real — unchanged by this pass
