# Changelog

## 1.0.0 - Unreleased

> Renamed from **Gated Content** (`iceboxind/craft-gated-content`) to **Downtoll**
> (`dgaidula/craft-downtoll`) before release. Nothing shipped under the old name, so
> there is no upgrade path to support: namespace `dgaidula\downtoll`, handle
> `downtoll`, Twig `craft.downtoll.*`.

### Added
- Initial release: configurable embedded gated lead-gen forms.
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
