# Changelog

## 1.2.0 - 2026-07-21

### Added
- **Full-state default gated configuration** (Pro) — a new **Settings → General →
  “Defaults for new gated pages (Pro)”** section lets an admin save an entire default
  `ResourceConfig` once, using the exact same editor UI as a real page (minus the asset
  picker). A brand-new (never-saved) **Gated Content** field then initializes from that
  saved default, so an editor only has to pick the asset on a fresh page. Saved/existing
  pages are never affected, an asset is never defaulted (`assetId` is always null), and
  Lite is byte-for-byte unchanged (feature hidden, hardcoded fresh-field defaults —
  affiliation on / newsletter off — retained). Stored in project config like every other
  setting; no content migration (only affects NEW pages), so the schema version is unchanged.

### Notes
- No schema change (`schemaVersion` stays `1.1.0`). Purely additive Settings + field
  initialization behavior, gated behind the Pro edition.

## 1.1.0 - unreleased

### Added
- **Submission storage + CP lead index** — every submission is now captured as a
  native Craft **Submission** element (both editions), so leads get a searchable,
  sortable, paginated CP index for free. The Downtoll CP section becomes a subnav:
  **Submissions** (the lead index) and **Form Config**. Commonly-queried fields are
  real columns on `{{%downtoll_submissions}}`; the full normalized Title-Case payload
  is also stored as JSON, so a new form field never needs a schema migration.
  Storage runs after access is granted and is failure-isolated — a storage hiccup
  can never cost a visitor their download. New permission `downtoll:viewSubmissions`.
- **Lead CSV export** (Pro) — the element index's Export button, gated to Pro via
  `Submission::defineExporters()`; Lite hides it.
- **Retention / purge** — new `submissionRetentionDays` setting (Settings → General;
  `0` keeps forever, `N` hard-deletes leads older than N days). Enforced during
  Craft's garbage collection (`Gc::EVENT_RUN`) and runnable on demand with
  `php craft downtoll/submissions/purge`. Non-optional privacy hygiene now that Lite
  stores PII.
- **`docs/DEVELOPMENT.md`** — a contributor/customizer development guide (architecture,
  editions model, extension points, data model, and the local dev loop).

### Notes
- Schema version → `1.1.0`. Existing installs get `{{%downtoll_submissions}}` via
  migration `m260716_120000_downtoll_submissions`; fresh installs via `Install`.

## 1.0.0 - 2026-07-16

> Renamed from **Gated Content** (`iceboxind/craft-gated-content`) to **Downtoll**
> (`dgaidula/craft-downtoll`) before release. Nothing shipped under the old name, so
> there is no upgrade path to support: namespace `dgaidula\downtoll`, handle
> `downtoll`, Twig `craft.downtoll.*`.

### Added
- Initial release: configurable embedded gated lead-gen forms.
- **Configurable CP name** (Settings → General → Plugin name, or `config/downtoll.php`)
  — relabel the plugin in the sidebar, Plugins screen, and settings. Blank = "Downtoll".
  For a seamless rename where editors keep the name they already know.
- **Lite / Pro editions.** Lite is a complete product — gate a file, capture the lead,
  get emailed. Pro adds the integration surface: `EVENT_AFTER_SUBMISSION`, the webhook,
  `data()` headless mode, multi-list opt-in + `triggersHook` routing, and the district
  lookup endpoint. Lite keeps the district text input; only the typeahead is Pro.
- **Lead notification emails** (both editions) — every captured lead emailed to a
  human, with an overridable plain-text template (`downtoll/_mail/notification`),
  env-aware recipients, `{download}`/`{site}` subject tokens, and Reply-To set to the
  lead. Sent after access is granted and failure-isolated, so a broken mailer can never
  cost a visitor their download.
- **Gated Content field type** — per-entry gating config (asset, newsletter list,
  success behavior), editable on prod with `allowAdminChanges` off.
- **CP section** for the plugin-owned form config (affiliation options +
  newsletter-list catalog), backed by the plugin's own DB table; gated by the
  *Manage gated form configuration* permission.
- `Submissions::EVENT_AFTER_SUBMISSION` custom event (the integration seam).
- `SubmitController` with server-side reCAPTCHA v3 verification (Guzzle).
- `DownloadController` serving assets via short-lived signed URLs.
- **Generic `WebhookIntegration`** — POSTs each submission to a configurable URL,
  optionally HMAC-signed. (Vendor CRM logic lives in site-side listeners.)
- Tabbed settings (General / Integrations / Advanced); credentials via env-aware
  autosuggest fields — no secrets bundled.
- `craft.downtoll.render()` (generic form) + `craft.downtoll.data()` (headless;
  bring-your-own front-end markup/JS).
- **District lookup endpoint** setting (Integrations tab) — env-aware, resolved
  server-side, and surfaced on `data()`. Empty (default) omits the attribute and the
  district input degrades to a plain text field. The plugin ships no typeahead JS.
- **`examples/`** — reference integrations: the generic webhook, plus a full
  real-world Salesforce + CampaignMonitor listener showing CRM Contact upsert,
  per-download engagement records, and opt-in/drip routing off the submission event.

### Notes

- The plugin core has **no CRM/ESP dependency**. The CampaignMonitor SDK is *not*
  required — vendor logic belongs in a site-side listener (see `examples/`). The
  declared `require` is `php >=8.2` + `craftcms/cms ^5.0`, verified to cover exactly
  what `src/` uses, with no coupling to any host site.
- **The plugin ships no front-end JS.** `render()` emits the form and the seams
  (`data-endpoint`, `data-lookup-endpoint`, the reCAPTCHA field), but the consuming
  site supplies the submit/typeahead/success behaviour. `data()` exists for exactly
  that. Shipping a turnkey JS bundle is the main outstanding item for Lite.
