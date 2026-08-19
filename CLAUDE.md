# SocialAuth — Plugin Context for Claude

> Plugin for the **AlfacodeTeam PhpServicePlatform** (Sentinel) kernel.
> Package `alfacode-team/hkm-plugin-social-auth` · namespace `Plugins\SocialAuth\` · solves `auth.social`
>
> This file is the rule set for THIS repository. It is self-contained for
> day-to-day work; the kernel-wide contracts it builds on are linked at the
> bottom. This is NOT Laravel, NOT Symfony, NOT Slim — do not suggest those
> frameworks' patterns, classes or conventions.

---

## WORKING RULES — VERSION CONTROL (ABSOLUTE)

**NEVER run `git commit`, `git push`, `git tag`, or `gh release` / `gh pr`
unless the user explicitly asks for it in that message.**

Write the changes, run the tests, report what changed — and stop. The user
decides when work is committed, pushed, tagged or released.

This matters more here than in an application repo. This plugin is a **published
package**: every project that requires it consumes what you push. A pushed tag is
immutable on Packagist and cannot be reused, so a premature release is not an
`undo`, it is a new version plus an explanation.

When the work is done, say what is uncommitted and let the user choose.

---

## WORKING RULES — VERIFICATION (READ THE DEFINITION)

**Before calling something you did not write, open its definition.** Not a call
site elsewhere — the definition.

This is not caution for its own sake; it is the measured failure mode of this
codebase. A review of ~5,600 lines of freshly written code found **15 defects**
that `php -l`, PHPStan and `tsc --noEmit` all passed. Every one was a coherent
but false belief about something the code called — a trait method that lived in a
different trait, a link pointing at a POST-only route, a framework default
assumed from the shape of an API rather than read from its source.

```
✓ Trait method   → find the trait that DECLARES it (traits compose; the obvious one is often wrong)
✓ Signature      → read it; argument order and names are not inferable
✓ Route          → read module.json for its METHOD, path, filters[] and requires[]
✓ env() key      → confirm it is in THIS module.json config[], or the boot fails
✓ Kernel API     → read the kernel source, not the shape of the call site
✓ Sibling plugin → read that plugin's API/Contracts/, never its internals
✓ A default that is a URL → someone will click it; confirm it resolves
```

State plainly what you did NOT verify. "Static analysis is clean" is a weak claim
here and must never be presented as evidence the code works. Code that has never
run against a real request has not been tested — say so in those words.

---

## WHAT THIS PLUGIN IS

| | |
|---|---|
| `solves` | `auth.social` |
| `requires` | `database.management`, `user.management`, `auth.identity`, `http.client` |
| `exposes` | `SocialAuthServiceContract` |
| `emits` | — |
| Activation | on-demand — a consumer declares it in `requires[]` |
| Namespace | `Plugins\SocialAuth\` |
| Version | `1.1.0` |
| Routes | 3 declared in `module.json` |
| Route prefix | `/auth/social` |

---

## `module.json` IS THE SINGLE SOURCE OF TRUTH

Routes, jobs, commands, views, emitted events and every environment variable this
plugin reads are declared in `module.json`. The kernel compiles them at boot.

```
✗ Declaring a route in a PHP file — routes exist ONLY in module.json
✗ Reading an env var that is not in config[] — ValidateConfigStage fails the boot
✗ Naming a requires[] entry that is not some module's solves domain — fails the boot
✗ Putting a port or contract CLASS name in requires[] — module DOMAINS only
✗ Dispatching an event whose name is not in emits[] — nothing is subscribed to it
```

`config[]` is also what `hkm plugins enable social-auth` seeds into the project's
`.env`, so a declared `default` is the value the operator actually receives.
Three shapes, and the difference is load-bearing:

| Declaration | Written to `.env` as | Why |
|---|---|---|
| has `default` | `KEY=value` (active) | the documented default, working out of the box |
| `required`, no default | `KEY=` (active, empty) | `''` counts as MISSING, so the boot still fails loudly until a real secret is supplied |
| optional, no default | `# KEY=` (commented) | `''` is a VALUE and would silently beat this plugin's own internal default |

### Environment variables (`config[]`)

| Key | Type | Required | Default |
|---|---|---|---|
| `SOCIAL_AUTH_BASE_URL` | string | no | — |
| `SOCIAL_AUTH_SUCCESS_REDIRECT` | string | no | — |
| `AUTH_MOBILE_ACCESS_TTL` | int | no | — |
| `GITHUB_CLIENT_ID` | string | no | — |
| `GITHUB_CLIENT_SECRET` | string | no | — |
| `GITHUB_REDIRECT_URI` | string | no | — |
| `GOOGLE_CLIENT_ID` | string | no | — |
| `GOOGLE_CLIENT_SECRET` | string | no | — |
| `GOOGLE_REDIRECT_URI` | string | no | — |
| `APPLE_CLIENT_ID` | string | no | — |

---

## THE FIVE ACCESS RULES — ABSOLUTE — RUNTIME ENFORCED

```
Controller  →  Service      (published contract interface ONLY)
Service     →  Repository  AND  Gateway   (the ONLY layer calling both)
Repository  →  DatabasePort ONLY          (no HTTP, no vendor SDK)
Gateway     →  Vendor SDK ONLY            (no DB, no services)
Domain      →  NOTHING EXTERNAL           (zero imports outside Domain/)
```

`ModuleContainer::bindInternal()` enforces these at runtime — violations throw
`ScopeViolationException`. A `bindInternal` binding is unreachable from any other
module even when that module declares this one in `requires[]`; only the
contracts in `exposes[]` cross the boundary.

Layer rules that apply to every file in this repo:

- **Controllers are ≤3 lines** — DTO in, service call, `Response` out. No business logic.
- **Services own the transaction + event shape** — `collector->beginCollection()`,
  `transaction->begin()`, work, `commit()`; on `\Throwable` `rollback()` **and**
  `collector->discard()`. Integration events dispatch **only after** a successful
  commit, never inside the `try`.
- **Repositories translate `\PDOException`** into `RepositoryException`, and scope
  every query by tenant where the data is tenant-owned.
- **Gateways translate every vendor exception** into `GatewayException`. No vendor
  exception type escapes the gateway.
- **Domain has zero external imports** and never dispatches — entities buffer
  events and `releaseEvents()`.
- **Money is integer cents in a value object**, never a float.
- **No `static` mutable state in request-scoped classes** — it leaks between
  requests under OpenSwoole.
- **`hash_equals()` for every token/secret comparison**, never `===`.

---

## WHAT THIS PLUGIN IS

OAuth **client** — signing in through GitHub, Google, Facebook, GitLab,
Bitbucket, LinkedIn, Slack, X and Apple. (Not to be confused with
`hkm-plugin-oauth2`, which is the authorization **server**.)

A small compat layer (`Socialite/Http`, `Socialite/Support`) lets the stateful
OAuth flow run inside the stateless kernel. OAuth2 drivers work out of the box;
the Twitter OAuth1 driver additionally needs `league/oauth1-client` + `phpseclib`.

```php
$social->redirectUrl('github');                 // start
$social->userFromCallback('github', $request);  // resolve user
```

Identities are persisted in `social_identities` — a **TENANT-scoped** table, so
it resolves the per-request `DatabasePort` and migrates via
`database/tenant-template/`.

## THE PARTS THAT ARE EASY TO GET WRONG

- **`state` is not optional.** It is the CSRF defence for the callback. A callback
  that does not verify `state` against what was issued lets an attacker complete
  a login as themselves in the victim's browser.
- **The web callback's redirect is open-redirect-guarded** — relative paths only,
  matching Auth's login behaviour. Keep that filter.
- **An email from a provider is not a verified email** unless the provider says
  it is verified. Linking accounts on an unverified email is an account-takeover
  path.

```
✗ Trusting the provider's `email` to match an existing account without checking
  the verified flag
✗ Storing a provider access/refresh token unencrypted — use EncryptionPort
✗ Logging the code, state, or token from the callback query string
```

---

## TESTING — USE THE GROUND, NOT A HAND-ROLLED BOOTSTRAP

A plugin is not a library: it is declared in `module.json`, compiled by the boot
pipeline, loaded through a dependency graph and resolved in a scoped container.
Almost everything that goes wrong with one goes wrong in that machinery, so a
unit test of its service proves very little — and standing up a whole project to
find out is why plugins go untested.

```php
$ground = PluginGround::for(Provider::class, DependencyProvider::class)
    ->as(Identity::asAdmin('tenant-1'))
    ->env(['SOME_KEY' => 5])
    ->boot();

$ground->db()->onQuery('from things', ['id' => 1]);
$ground->get('/things')->status();                 // the real HttpPipeline
$ground->service(SomeServiceContract::class);      // resolved in this plugin's scope
$ground->events()->dispatched('thing.created');
$ground->destroy();                                // ALWAYS — restores $_ENV + Paths
```

Three behaviours that are easy to get wrong:

- **Security is OPEN by default.** `BindSecurityStage` refuses an empty layer list
  (fail-closed), so the ground installs an allow-all stand-in. Passing any layer to
  `security()` REPLACES it — which is what a test about auth wants.
- **Events are recorded from `emits[]` only.** `EventBus` is `final` with no
  wildcard, so an event dispatched under a name the manifest does not declare is
  never recorded. It reads as "nothing dispatched"; check the manifest first.
- **Required config with no default is filled with a PLACEHOLDER** so the boot
  proceeds. `placeholders()` lists them — anything asserted while one is in play is
  asserted against a stand-in.

`hkm plugin:check` runs the static GDA + UI checks the boot cannot catch and exits
non-zero, so it gates CI. `hkm plugin:probe` boots this plugin for real and
resolves `requires[]` transitively.

```
✗ Booting this plugin against a REAL project root in a test — the kernel writes
  compiled manifests under the active project, so it overwrites that project's
  route/service/config manifests and leaves them that way. The ground's temp
  workspace exists for exactly this.
✗ Leaking a ground (no destroy()) — $_ENV stays mutated and Paths points at a
  deleted directory; the symptom is an unrelated later test failing.
✗ Trusting a "route is protected" assertion while its filter is STUBBED —
  stubbedFilters() lists aliases that did NOT run (auth/throttle come from
  SecurityFilters; load it, or use ->realFilters(), when the filter is the subject).
```

---

## WHAT NEVER TO GENERATE IN THIS REPO

```
✗ git commit / push / tag, or gh pr / gh release, without being asked in that message
✗ Laravel / Symfony / Slim patterns, classes or conventions
✗ Eloquent, Doctrine, Active Record or any ORM — LetMigrate + DatabasePort only
✗ Routes defined in PHP — module.json is the only place
✗ Env vars read but not declared in module.json config[]
✗ Port or contract CLASS names in requires[] — module domains only
✗ Business logic in a Controller — max 3 lines: DTO → service → Response
✗ Integration events dispatched inside a try{} — ONLY after commit
✗ A catch block that rolls back without collector->discard() — phantom events
✗ Vendor exceptions (\PDOException, Stripe, Guzzle) escaping their layer
✗ Another plugin's internal class imported — use its published contract
✗ Authorization decisions in a SecurityLayer — those belong in the Service
✗ float for money — integer cents in a value object
✗ === for token comparison — always hash_equals()
✗ Static mutable state in request-scoped classes — leaks across Swoole requests
✗ Injecting Request or Response into a Service or Repository
✗ Hand-writing ON DUPLICATE KEY / ON CONFLICT — call $db->upsert() (driver-portable)
✗ Adding a kernel change to make this plugin work — the kernel stays domain-ignorant
```

---

## KERNEL REFERENCE

The kernel documents the contracts; this repo documents the plugin. When they
disagree, the code wins — read the definition.

| Topic | Guide |
|---|---|
| Architecture + request lifecycle | [00_SENTINEL_OVERVIEW](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/00_SENTINEL_OVERVIEW.md) |
| Kernel internals | [01_KERNEL](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/01_KERNEL.md) |
| Module contract + `module.json` | [02_MODULE](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/02_MODULE.md) |
| Domain layer | [03_DOMAIN](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/03_DOMAIN.md) |
| Service layer + transaction/event pattern | [04_SERVICE](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/04_SERVICE.md) |
| Repository layer | [05_REPOSITORY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/05_REPOSITORY.md) |
| Gateway layer | [06_GATEWAY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/06_GATEWAY.md) |
| Controllers + DTO validation | [07_CONTROLLER](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/07_CONTROLLER.md) |
| Events | [08_EVENTS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/08_EVENTS.md) |
| SecurityGateway + Identity | [09_SECURITY](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/09_SECURITY.md) |
| Testing + port fakes | [10_TESTING](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/10_TESTING.md) |
| Workers + jobs | [12_WORKER](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/12_WORKER.md) |
| Antipatterns | [13_ANTIPATTERNS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/13_ANTIPATTERNS.md) |
| Writing a plugin | [16_PLUGINS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/16_PLUGINS.md) |
| Migrations (LetMigrate) | [18_MIGRATIONS](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/18_MIGRATIONS.md) |
| CSRF | [21_CSRF](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/21_CSRF.md) |
| Routing cookbook | [30_ROUTING_COOKBOOK](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/30_ROUTING_COOKBOOK.md) |

Sibling plugins are separate repositories under
`github.com/AlfaCode-Team/hkm-plugin-<name>`. Depend on one through its
`exposes[]` contract and a `requires[]` domain — never by reaching into its tree.
