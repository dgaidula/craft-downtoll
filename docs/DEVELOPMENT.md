# Downtoll — Development Guide

**Charge a toll for your best content. The toll is an email address.**

This guide is for developers who want to customize Downtoll, build an integration
against it, or contribute to it. It covers the plugin’s architecture, its Lite/Pro
editions model, every extension point, the data model, and the local development
loop. For install-and-use documentation, start with the [README](../README.md).

## Table of contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [The editions model](#the-editions-model)
4. [Extension points](#extension-points)
5. [The field and the config layers](#the-field-and-the-config-layers)
6. [Data model and storage](#data-model-and-storage)
7. [Developing against a host site](#developing-against-a-host-site)
8. [Coding standards and layout](#coding-standards-and-layout)

---

## Overview

> **Requirements:** Craft CMS 5.0+ and PHP 8.2+ (tested through 8.4). Downtoll is a Craft 5 plugin and
> does not run on Craft 4 — the element layer uses Craft 5 base-class APIs (e.g.
> `attributeHtml()`, renamed from Craft 4’s `tableAttributeHtml()`). Craft 4 support,
> if ever needed, would be a separate `^4.0` version line rather than a dual-compatible
> codebase, as is conventional for Craft plugins.

Downtoll is gated downloads plus lead capture for Craft CMS 5. A developer adds
the **Gated Content** field to any entry type once; editors then configure gating
per entry — which asset, which newsletter lists, what happens on success — as
ordinary content. A template renders the form with one call:

```twig
{{ craft.downtoll.render(entry.myGatedField) }}
```

On submit, Downtoll validates the payload, verifies a reCAPTCHA v3 token
server-side, stores the lead as a native Craft element, emails it to a human, and
releases the download — either a short-lived signed URL (`swap` mode) or a session
unlock that reveals content in place (`reload` mode).

The plugin is deliberately **integration-agnostic**: it bundles no CRM or ESP
code. Its integration surface is a single event
(`Submissions::EVENT_AFTER_SUBMISSION`) plus a shipped, vendor-neutral
`WebhookIntegration` that is itself just a listener on that event. Everything
vendor-specific belongs in a site-side listener — see
[Extension points](#extension-points).

Downtoll ships in two editions, **Lite** (free) and **Pro** (paid). The dividing
line is “works standalone” vs. “integrates with your systems” — Lite is a complete
product, not a trial. The [editions model](#the-editions-model) section maps every
gate to the line of code that enforces it.

## Architecture

### The `Plugin` bootstrap

`src/Plugin.php` is the whole wiring diagram. `Plugin::config()` registers four
service components:

| Component | Class | Responsibility |
| --- | --- | --- |
| `submissions` | `services\Submissions` | The submission lifecycle: config enrichment, payload normalization, the `EVENT_AFTER_SUBMISSION` event, signed config/download tokens, the session access gate, element storage, retention purge. |
| `formConfig` | `services\FormConfig` | Reads/writes the plugin-owned form catalog (affiliation options + newsletter lists) in `{{%downtoll_config}}`. |
| `webhook` | `services\WebhookIntegration` | The shipped generic listener: POSTs each lead to a configurable URL, optionally HMAC-signed. |
| `notifications` | `services\Notifications` | Emails each captured lead to a human (both editions). |

`Plugin::init()` then registers, via Yii events:

- **Field type** — `fields\GatedContent` on `Fields::EVENT_REGISTER_FIELD_TYPES`.
- **Element type** — `elements\Submission` on `Elements::EVENT_REGISTER_ELEMENT_TYPES`.
- **Site routes** — `downtoll/submit` (form endpoint), `downtoll/download`
  (signed-token asset delivery), and `downtoll/preview` (dev-only, 404s outside
  `devMode`).
- **CP routes** — `downtoll`, `downtoll/submissions` (lead index) and
  `downtoll/config` (form catalog screen).
- **Permissions** — `downtoll:manageConfig` and `downtoll:viewSubmissions`
  (constants `PERMISSION_MANAGE` / `PERMISSION_VIEW_SUBMISSIONS`), so both CP
  screens are available to editors, not only admins.
- **Twig variable** — `craft.downtoll` → `web\twig\DowntollVariable`.
- **Template roots** — `src/templates/` is registered as the `downtoll` root for
  both CP and site template modes, which is what lets a site override the
  notification email template (site template roots win).
- **Garbage collection** — a listener on `Gc::EVENT_RUN` calls
  `Submissions::purgeExpired()`, so submission retention is enforced during
  Craft’s normal `php craft gc` cycle.
- **The Pro webhook listener** — only on Pro, `WebhookIntegration::handleSubmission`
  is attached to `Submissions::EVENT_AFTER_SUBMISSION`. On Lite the event never
  fires, so not attaching it just makes that explicit.

`init()` also applies the optional **plugin rename**: `Settings::$pluginName`
relabels the plugin everywhere in the CP (sidebar, Plugins screen, settings
breadcrumb) so a site that has always called this feature something else never has
to surface the package name to editors. Blank keeps “Downtoll”.

### The pieces, walked

- **`fields/GatedContent.php`** — the field type. `normalizeValue()` decodes the
  stored per-entry JSON into a `ResourceConfig`, then asks
  `Submissions::enrich()` to fill in the catalog-derived parts (affiliation
  options, offered newsletter lists, hook IDs, headings). Templates therefore
  always receive a fully-resolved model. `serializeValue()` writes back only the
  per-entry keys.
- **`models/ResourceConfig.php`** — the per-resource form model. Half its
  properties come from the field value (content), half are enriched at render
  time. `toTokenArray()` / `fromTokenArray()` define the minimal server-trusted
  subset that rides in the signed `_gcConfig` token (see
  [the token](#the-signed-_gcconfig-token)).
- **`models/Settings.php`** — global settings, stored in **project config**.
  Credential-shaped fields (reCAPTCHA keys, webhook URL/secret, lookup endpoint,
  notification recipients) accept a literal or a `$ENV_VAR` reference, resolved
  server-side with `craft\helpers\App::parseEnv()` — no secrets are ever bundled
  or shipped to the browser.
- **`elements/Submission.php`** + **`elements/db/SubmissionQuery.php`** — the
  captured lead as a native element (CP search/sort/pagination for free). The
  query joins the `{{%downtoll_submissions}}` sub-table and adds fluent
  `email()` / `resourceId()` filters.
- **`events/SubmissionEvent.php`** — the event object listeners receive; the
  full contract is documented under [Extension points](#extension-points).
- **Controllers:**
  - `SubmitController` (site, anonymous) — the whole submit pipeline in one
    readable action: decode + trust the signed token → verify reCAPTCHA →
    normalize/validate → fire the event → grant session access → store the
    element → notify → respond per the editor’s success mode. Storage and
    notification run *after* access is granted and can never fail the request:
    the visitor has already earned the download.
  - `DownloadController` (site, anonymous) — validates the short-lived signed
    token and streams the asset as an attachment, so the raw asset URL is never
    exposed.
  - `PreviewController` (site, dev-only) — renders a standalone, fully wired form
    without creating any section or entry, so front-end behavior can be exercised
    in a browser or by end-to-end tests. Hard-gated behind `devMode`; 404
    everywhere else.
  - `ConfigController` (CP) — the form-catalog screen, plus CSV export/import of
    the catalog for copying config between environments. Requires
    `downtoll:manageConfig`.
  - `SubmissionsController` (CP) — the lead index. Requires
    `downtoll:viewSubmissions`.
  - `console/controllers/SubmissionsController` — Craft auto-registers plugin
    console controllers, so `php craft downtoll/submissions/purge` runs the
    retention purge on demand.
- **Templates** (`src/templates/`) — `_form.twig` is the shipped form markup;
  `_field/input.twig` is the CP field UI; `_mail/notification.twig` is the
  (site-overridable) lead email; `index.twig` / `settings.twig` /
  `submissions/_index.twig` are the CP screens.
- **`web/assets/form/`** — `FormAsset` bundles `dist/downtoll-form.js`, the
  turnkey front-end for the `render()` form: reCAPTCHA token generation, the JSON
  submit round-trip, inline field errors, swap/reload success handling, and the
  affiliation → district toggle. Dependency-free, idempotent, no build step. It
  is registered only by `render()` — headless `data()` users never carry JS they
  won’t use, and a site may replace it wholesale since it relies only on the
  markup and response contracts.

## The editions model

```php
public const EDITION_LITE = 'lite';
public const EDITION_PRO  = 'pro';

/** Single place the Pro gates ask. */
public function isPro(): bool
{
    return $this->is(self::EDITION_PRO);
}
```

The design principle: **capture and store is free; integrations are paid.** Lite
gates a file behind a form, captures the lead as an element, and emails it to a
human — a complete product on its own. Pro sells the surface that wires those
leads into *your* systems. Every gate funnels through `Plugin::isPro()`, so there
is exactly one question asked in exactly one way.

Where each gate lives:

| Pro capability | Gate location | Lite behavior |
| --- | --- | --- |
| `craft.downtoll.data()` headless mode | `DowntollVariable::data()` | Logs a warning and returns `[]`; Lite ships `render()` instead. |
| `EVENT_AFTER_SUBMISSION` firing | `Submissions::fireAfterSubmission()` | The event object is still built and returned (the caller’s contract is unchanged, `isValid` stays `true`), but `trigger()` is never called — so no listener, including the shipped webhook, runs. |
| Webhook listener attachment | `Plugin::init()` | Not attached (it would be inert anyway, since the event never fires). |
| Multi-list opt-in + `triggersHook` routing | `Submissions::enrich()` | The offered lists are sliced to a single plain opt-in and all hook flags/IDs are cleared. Capping in `enrich()` — not in the form — means `render()`, `data()`, and the submit path all agree, and a crafted POST can’t smuggle extra list IDs past Lite. |
| District-lookup endpoint | `DowntollVariable::districtLookupEndpoint()` | Returns `''`, so the form omits `data-lookup-endpoint` and the district input degrades to a plain text field (which still submits as `School District Input`). |
| Lead CSV export | `Submission::defineExporters()` | Returns `[]`, hiding the element index’s Export button. |

When adding a new Pro feature, follow the same pattern: gate it **server-side**,
at the narrowest choke point, via `Plugin::getInstance()->isPro()`, and leave the
Lite fallback genuinely useful.

## Extension points

### `Submissions::EVENT_AFTER_SUBMISSION`

Fired (Pro) after a submission passes validation and reCAPTCHA, **before** the
resource is gated. This is the plugin’s primary integration seam. The
`SubmissionEvent` object carries:

| Property | Direction | Notes |
| --- | --- | --- |
| `$fields` | read | Normalized Title-Case payload: `Email`, `First Name`, `Last Name`, `State`, `Affiliation`, `School District Input`, `District Id`, `Other Affiliation`, plus `Newsletter Lists` (array of checked list IDs). Keys are *absent*, not empty, when not submitted. |
| `$config` | read | The per-resource `ResourceConfig` (the token-trusted subset is server-trusted). |
| `$rawPayload` | read | The raw, **untrusted** POST, for listeners that need unmapped keys. |
| `$triggeredHooks` | read | Checked list IDs flagged `triggersHook` — the routing modifiers. |
| `$downloadName` | read | The gated file’s human-readable name, resolved server-side from the signed asset ID — never a client-posted value, so it can’t be spoofed. `null` when no asset is gated. |
| `$contactId` | write | Your CRM’s identifier for the lead, if a listener creates/updates one. |
| `$isValid` | write | Set `false` to hard-gate — block the download on a hard integration failure. Defaults to `true`. |
| `$integrationResults` | write | Free-form bag for listener outcome data. |

### Writing a site-side listener

Register in a Craft module’s `init()` and bootstrap the module in
`config/app.php` so the handler is attached on every request:

```php
use yii\base\Event;
use dgaidula\downtoll\services\Submissions;
use dgaidula\downtoll\events\SubmissionEvent;

Event::on(Submissions::class, Submissions::EVENT_AFTER_SUBMISSION, function (SubmissionEvent $e) {
    $checked    = (array) ($e->fields['Newsletter Lists'] ?? []);
    $plainOptIn = array_values(array_diff($checked, $e->triggeredHooks));

    // Subscribe only on a genuine opt-in; a hook box on its own just classifies.
    // List IDs may be $ENV_VAR references — resolve them at the API call:
    // \craft\helpers\App::parseEnv($listId).
    // ...push the lead + $e->downloadName to your CRM/ESP...
});
```

Multiple listeners can subscribe; they all run. Keep each listener’s failures to
itself (catch `Throwable`) unless blocking the download is the intent — the
plugin’s own rule is that an integration hiccup must never cost a visitor a
download they already earned.

For a complete, production-shaped reference — a CRM contact upsert, one
timestamped engagement record per download, opt-in vs. drip-list routing,
env-only configuration, and the error-handling gotchas — see the integration
example under [`examples/`](../examples/). Its README also explains *why* there
is no “CRM settings” tab: an event costs one `Event::on()` and can express
anything PHP can, where a settings tab would be a permanent guessing game about
your particular CRM’s schema.

### The generic webhook (and HMAC signing)

`services/WebhookIntegration.php` is the one integration that ships enabled-able
out of the box, and it is deliberately the simplest possible listener to read: it
POSTs a JSON body (`fields`, `resource` context, `submittedAt`) to the configured
URL via Craft’s Guzzle client, soft-failing on error. If a webhook secret is set,
the payload is signed:

```
X-Downtoll-Signature: sha256=<hex HMAC-SHA256 of the raw body>
```

Receivers verify by recomputing the HMAC over the exact raw body with the shared
secret. Because it knows nothing about any vendor, it works with any automation
platform or bespoke endpoint unchanged.

### `render()` vs. `data()`

- **`craft.downtoll.render(fieldValue)`** — the turnkey path. Emits the shipped
  `_form.twig` markup and registers `FormAsset` (the shipped JS). Best for a
  quick start, and a readable reference for what a custom form must post.
- **`craft.downtoll.data(fieldValue)`** *(Pro)* — the headless path. Returns
  everything a bespoke front end needs — the signed `token`, the `endpoint`, the
  reCAPTCHA site key, `successMode`, `hasAccess`, `downloadUrl`, the offered
  newsletter lists (each row carrying its own `triggersHook` flag), affiliation
  options, `triggerValues`, `districtLookupEndpoint`, a `states` map, and the
  exact `fieldNames` the controller maps — and renders nothing. Validation,
  reCAPTCHA, and gating are all reused because the custom form still posts to the
  same controller. Only the field `name`s and the signed token are load-bearing.

The variable also exposes the smaller pieces individually (`token()`,
`hasAccess()`, `downloadUrl()`, `recaptchaSiteKey()`, `recaptchaFieldName()`,
`districtLookupEndpoint()`, `usStates()`) for templates that need just one.

## The field and the config layers

Downtoll splits configuration across **three layers**, each owned by the right
role and mutable in the right environment:

1. **The `GatedContent` field value — per-entry CONTENT.** Which asset, success
   mode, required fields, which catalog lists this resource offers, headings and
   messages, a CSS class hook. Stored as the field’s serialized JSON, edited on
   the entry, fully editable on production. The field definition itself (adding
   it to an entry type) is project config, deployed once by a developer.
2. **The `FormConfig` catalog — plugin-owned CONTENT.** The affiliation options
   and the newsletter-list catalog (`{ label, listId, triggersHook }` rows) live
   in the plugin’s own `{{%downtoll_config}}` table, edited on the plugin’s CP
   screen by anyone with the `downtoll:manageConfig` permission. Because it is a
   plain DB table — *not* project config — editors can manage form structure and
   routing on production even with `allowAdminChanges` off, and the plugin stays
   fully self-contained. List IDs may be `$ENV_VAR` references so a real
   list identifier never ships in page HTML.
3. **`Settings` — PROJECT CONFIG, dev-owned.** Deploy-time values: default
   success mode, notification config, reCAPTCHA keys, webhook URL/secret, the
   district-lookup endpoint, download TTL, retention days, the plugin name.
   Locked on production when `allowAdminChanges` is off — by design.

The rule of thumb: if an editor should be able to change it on production, it is
content (layers 1–2); if it is an environment or deployment concern, it is
project config (layer 3).

### The signed `_gcConfig` token

The rendered form carries one hidden field, `_gcConfig`, produced by
`Submissions::encodeConfigToken()` — the JSON of
`ResourceConfig::toTokenArray()`, signed with Craft’s `Security::hashData()`.
The token carries only the **server-trusted subset** of the config: the resource
ID, asset ID, success mode, the include flags, the offered newsletter list IDs,
the hook-flagged IDs, the required-field set, and the affiliation trigger values.

On submit, `SubmitController` refuses to look at anything except this token for
per-resource decisions: `decodeConfigToken()` validates the signature and
reconstructs the `ResourceConfig`. That is the whole threat model in one move —
a crafted POST cannot re-point the request at an arbitrary asset, add newsletter
lists the editor never offered, weaken the required-field set, or forge hook
flags, because none of those values are read from mutable client input. It is
also why the submit endpoint can safely disable CSRF validation for its
JSON-fetch consumers: the signed token is the request-authenticity guarantee.

Download links use the same primitive: `signedDownloadUrl()` signs
`{asset id, expiry}` with the `downloadTtl` setting (default 900 seconds), and
`DownloadController` streams the asset only for a valid, unexpired token.

## Data model and storage

### `{{%downtoll_config}}`

Created by `migrations/Install.php`. A single-row table holding the whole form
catalog as one JSON `configData` column, upserted by `FormConfig::save()` (which
also filters empty rows and normalizes the `triggersHook` flag to a real bool,
accepting a legacy key name on read for back-compat).

### `{{%downtoll_submissions}}`

Lead storage, following the standard Craft **element sub-table** pattern: the
table’s `id` is a foreign key to `{{%elements}}.id` (cascade delete), so
submissions get search, sorting, pagination, sources, and (Pro) export from the
native element index for free.

Commonly-queried fields are real, indexed columns — `email`, `firstName`,
`lastName`, `state`, `affiliation`, `otherAffiliation`, `schoolDistrict`,
`districtId`, `downloadName`, `resourceId`, `siteId`, `newsletterLists` — and the
`payload` column keeps the **full normalized Title-Case submission as JSON**, so
adding a new form field never requires a schema migration: it simply appears in
`payload` (and on the event) until it earns a column of its own.

For existing installs the sub-table is added by
`m260716_120000_downtoll_submissions`, which mirrors `Install` and is idempotent.

### Retention and purge

Stored submissions are PII (names, emails), so retention is bounded:

- **`submissionRetentionDays`** (Settings → General) — `0` (default) keeps
  submissions forever; a positive `N` hard-deletes submissions older than `N`
  days.
- **The Gc hook** — `Plugin` listens on `Gc::EVENT_RUN`, so the purge runs during
  `php craft gc` and Craft’s scheduled garbage collection; it is a no-op while
  the setting is `0`.
- **`php craft downtoll/submissions/purge`** — runs the same purge on demand.

`Submissions::purgeExpired()` measures age by the *element’s* `dateCreated`,
collects IDs first, then hard-deletes one element at a time (bypassing the trash,
so the PII is actually gone), and never lets one bad row abort the whole purge.

## Developing against a host site

The plugin repo contains no Craft installation — you develop it inside any Craft
5 host site (a DDEV project works well). Point the host site’s Composer at your
local clone with a **path repository**, which symlinks the plugin so edits are
live immediately:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../craft-downtoll",
      "options": { "symlink": true }
    }
  ]
}
```

Then, from the host site:

```bash
composer require dgaidula/craft-downtoll:@dev
php craft plugin/install downtoll
php craft up            # applies any pending plugin migrations
```

The dev loop from there:

1. Edit the plugin under your clone — changes apply on the next request (clear
   compiled classes/templates with `php craft clear-caches/compiled-classes` or
   `.../compiled-templates` if Craft is caching what you changed).
2. Exercise the front end without creating content via the dev-only preview
   route: `/downtoll/preview` (add `?mode=reload` or `?asset=<id>` to vary it).
   It requires `devMode` and 404s everywhere else.
3. Verify the full pipeline: submit the form, confirm the Submission element in
   the CP index, the notification email (a local mail catcher helps), and the
   signed download.

**When you change the schema:** bump `Plugin::$schemaVersion`, add a migration
under `src/migrations/` (`php craft migrate/create <name> --plugin=downtoll`
scaffolds one), mirror the change in `Install.php` for fresh installs, and keep
the migration idempotent — the existing `m260716_120000_downtoll_submissions` is
the template to follow. Craft prompts for (or `php craft up` applies) pending
plugin migrations whenever the recorded schema version is behind.

## Coding standards and layout

- **PHP** ≥ 8.2, **Craft** ^5.0 — the only runtime requirements; the plugin core
  has no CRM/ESP or host-site coupling.
- **PSR-4** — everything under `src/` maps to the `dgaidula\downtoll\` namespace.
- **Code style** — `php-cs-fixer` (via the dev-only shim), configured in
  `.php-cs-fixer.dist.php`:

```bash
composer cs-check   # dry-run with a diff
composer cs-fix     # apply
```

- **House rules worth knowing before a PR:**
  - Pro gates go through `Plugin::isPro()`, server-side, at the narrowest choke
    point.
  - Anything that runs after access is granted (storage, notification, webhooks,
    listeners) soft-fails: log, never throw — the visitor’s download is already
    earned.
  - Secrets are never stored or rendered; credential settings accept `$ENV_VAR`
    references resolved server-side with `App::parseEnv()`.
  - Per-resource decisions on submit are trusted only via the signed token.

### `src/` directory map

```
src/
├── Plugin.php                     # Bootstrap: components, events, routes, permissions
├── icon.svg
├── console/controllers/
│   └── SubmissionsController.php  # php craft downtoll/submissions/purge
├── controllers/
│   ├── ConfigController.php       # CP: form catalog screen + CSV export/import
│   ├── DownloadController.php     # Site: signed-token asset delivery
│   ├── PreviewController.php      # Site: dev-only standalone form preview
│   ├── SubmissionsController.php  # CP: lead index
│   └── SubmitController.php       # Site: the submit pipeline
├── elements/
│   ├── Submission.php             # The captured lead (native element)
│   └── db/SubmissionQuery.php     # Element query + email()/resourceId() filters
├── events/
│   └── SubmissionEvent.php        # The integration event contract
├── fields/
│   └── GatedContent.php           # The per-entry gating field
├── migrations/
│   ├── Install.php                # Both plugin tables
│   └── m260716_120000_downtoll_submissions.php
├── models/
│   ├── ResourceConfig.php         # Per-resource form model + signed-token subset
│   └── Settings.php               # Global settings (project config)
├── services/
│   ├── FormConfig.php             # Plugin-owned catalog ({{%downtoll_config}})
│   ├── Notifications.php          # Lead notification emails
│   ├── Submissions.php            # Submission lifecycle + tokens + purge
│   └── WebhookIntegration.php     # The shipped generic listener
├── templates/
│   ├── _field/input.twig          # CP field UI
│   ├── _form.twig                 # The shipped front-end form
│   ├── _mail/notification.twig    # Lead email (site-overridable)
│   ├── index.twig                 # CP: form catalog
│   ├── settings.twig              # CP: tabbed settings
│   └── submissions/_index.twig    # CP: lead index
└── web/
    ├── assets/form/               # FormAsset + dist/downtoll-form.js
    └── twig/DowntollVariable.php  # craft.downtoll.render()/data()/…
```

Worked integration references live outside `src/`, under
[`examples/`](../examples/) — read the shipped `WebhookIntegration` first (the
simplest real listener), then the full CRM/ESP example for the
production-shaped version.
