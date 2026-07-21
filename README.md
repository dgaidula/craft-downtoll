# Downtoll

**Charge a toll for your best content. The toll is an email address.**

Gated downloads and lead capture for Craft 5. Put any file behind a form: Downtoll
validates the submission, emails you the lead, and releases the download.
Self-contained — no CRM bundled.

## Requirements

**Craft CMS 5.0+ · PHP 8.2+ (tested through 8.4).** Downtoll is a Craft 5 plugin and
does not run on Craft 4.

## What it does

1. **Field type** — add a *Gated Content* field to any entry type. Editors then
   configure gating **per entry** (asset, newsletter list, success behavior) as
   content, editable on production even with `allowAdminChanges` off.
2. **Renders** the embedded form: `{{ craft.downtoll.render(entry.myField) }}`.
3. **Validates** the submission and verifies a reCAPTCHA v3 token **server-side**
   (Craft's Guzzle client; secret read via env-aware setting).
4. **Notifies** — emails each captured lead to whoever should see it.
5. **Gates** the resource: a short-lived signed download URL (`swap` mode) or a
   session unlock that reveals content in place (`reload` mode).
6. **Integrates** *(Pro)* — fires `Submissions::EVENT_AFTER_SUBMISSION` and ships a
   generic `WebhookIntegration`. Vendor CRM logic lives in site-side listeners on the
   event (see [`examples/`](examples/)), never in this plugin.

## Editions

Downtoll ships two editions. The line is **works standalone** vs **integrates with
your systems** — the free edition is a complete product, not a trial.

| | Lite (free) | Pro |
| --- | :---: | :---: |
| Gated Content field + per-entry config | ✅ | ✅ |
| `render()` embedded form | ✅ | ✅ |
| Server-side reCAPTCHA v3 | ✅ | ✅ |
| Signed download / session unlock | ✅ | ✅ |
| **Lead notification emails** | ✅ | ✅ |
| Newsletter opt-in checkbox | single | **multi-list** |
| **Defaults for new gated pages** | — | ✅ |
| `triggersHook` routing modifiers | — | ✅ |
| District lookup typeahead endpoint | — | ✅ |
| `EVENT_AFTER_SUBMISSION` (custom CRM/ESP listeners) | — | ✅ |
| `WebhookIntegration` + HMAC signing | — | ✅ |
| `data()` headless mode | — | ✅ |

Lite keeps the district **text input** (it still submits as `School District Input`);
only the typeahead endpoint is Pro. On Lite the event never fires, so no listener —
including the shipped webhook — runs; leads reach you by email instead.

## Configuration (two layers, by design)

- **Downtoll** CP section → **affiliation options** + **newsletter-list
  catalog**. Stored in the plugin's own DB table (content) → editable on prod,
  manageable by editors with the *Manage gated form configuration* permission.
- **Settings** (project config; dev-owned, locked on prod) → tabbed
  *General / Integrations / Advanced*: plugin name, default success mode, lead
  notifications, reCAPTCHA + webhook config (literal or `$ENV_VAR`), district-lookup
  endpoint, download-link TTL.

### Rename it in the control panel

Downtoll can present under any name you like — the sidebar nav, the Plugins screen,
and the settings breadcrumb all follow **Settings → General → Plugin name** (blank =
"Downtoll"). Set it in `config/downtoll.php` so it holds with `allowAdminChanges` off:

```php
// config/downtoll.php
return [
    'pluginName' => 'Gated Content',
];
```

This exists for a seamless rename: if editors have always known this feature by
another name, keep that label so the internal package name is never something they
have to notice.

### Defaults for new gated pages <sup>PRO</sup>

When creating brand-new gated pages, editors typically configure the same settings
over and over: affiliation option, newsletter lists, success behavior, and so on.
Pro adds **Settings → General → Defaults for new gated pages**, where an admin saves
an entire default configuration once using the same editor UI as a real page (just
without the asset picker). Any *fresh* (never-saved) Gated Content field then
initializes from that saved default, so editors only have to choose the asset.

Saved and existing pages are never affected — the defaults only seed empty fields.
An asset is never defaulted (`assetId` is always null). On Lite, defaults are hidden
and fresh fields retain the standard hardcoded defaults (affiliation on, newsletter off).

### Lead notifications

**Settings → General → Lead notifications.** Turn it on, give it recipients
(comma-separated; literal addresses or an `$ENV_VAR`, resolved server-side), and every
captured lead is emailed to a human. `{download}` and `{site}` are available in the
subject. Reply-To is set to the lead, so hitting reply reaches the person.

This is what makes Lite a complete product: without it — and without a Pro listener —
a submission is validated, gated, served, and then gone.

The email is plain text, and overridable: copy the plugin's
`_mail/notification.twig` to `downtoll/_mail/notification.twig` in your own
templates. Site template roots win.

> Notification failures are swallowed and logged, never thrown. The send happens
> *after* access is granted, so a broken SMTP config can never cost a visitor the
> download they already earned.

### District lookup <sup>PRO</sup>

The affiliation dropdown can reveal a district/organization typeahead (see
*Triggers hook*, below). Set **Integrations → District lookup → Lookup endpoint**
to the URL your front end should query; it is env-aware and resolved server-side,
and is emitted as `data-lookup-endpoint` on the input (and returned from `data()`).

Leave it empty and no attribute is rendered — the input degrades to a plain text
field that still submits as `School District Input`. **The plugin ships no typeahead
JS**; supplying the endpoint *and* the front-end behavior is the consuming site's job.

## Template usage

```twig
{{ craft.downtoll.render(entry.myGatedField) }}
```

For `reload` mode, provide the protected markup in a
`{% block downtollRevealed %}…{% endblock %}` in the calling template.

### Headless / custom markup (advanced) <sup>PRO</sup>

When you want full control of the layout, call `data()` instead of `render()`.
It returns everything needed to build a bespoke form that still posts to the
plugin's controller (validation + reCAPTCHA + gating are reused) — only the
field `name`s and the signed token are load-bearing:

```twig
{% set gc = craft.downtoll.data(entry.myGatedField) %}
<form method="post" data-endpoint="{{ gc.endpoint }}" data-mode="{{ gc.successMode }}">
  <input type="hidden" name="{{ gc.fieldNames.config }}" value="{{ gc.token }}">
  <input type="hidden" name="{{ gc.fieldNames.recaptcha }}" id="recaptcha-response"
         data-parameter="{{ gc.recaptchaSiteKey }}">

  <input type="email" name="{{ gc.fieldNames.email }}" required>
  <input type="text"  name="{{ gc.fieldNames.firstName }}" required>
  {# …your own markup, classes, grid… #}

  {# Newsletter opt-ins are a MULTI-select: one checkbox per offered list, posted as
     an array, each carrying its own list ID as the value. #}
  {% if gc.includeNewsletter %}
    {% if gc.newsletter.heading %}<legend>{{ gc.newsletter.heading }}</legend>{% endif %}
    {% for list in gc.newsletter.lists %}
      <label>
        <input type="checkbox" name="{{ gc.fieldNames.newsletter }}[]" value="{{ list.listId }}">
        {{ list.label|raw }}
      </label>
    {% endfor %}
  {% endif %}
</form>
```

`data()` keys: `token`, `endpoint`, `recaptchaSiteKey`, `successMode`, `hasAccess`,
`downloadUrl`, `assetId`, `successMessage`, `errorMessage`, `includeAffiliation`,
`includeNewsletter`, `requiredFields`, `cssClass`,
`newsletter{heading, lists[]{listId, label, triggersHook}}`, `affiliations[]`,
`triggerValues[]` (affiliation values flagged to fire a front-end hook),
`districtLookupEndpoint`, `states{}`, and `fieldNames{}`. Wire your own submit JS
to POST the form as JSON to `endpoint`.

> Each newsletter row carries its own `triggersHook` flag, so a custom front end can
> tell a routing-modifier box from a plain opt-in without a second lookup. You rarely
> need it client-side — hook boxes are interpreted **server-side** and arrive on the
> event as `$e->triggeredHooks` — but it's there if you want to style or group them.

> Note: `fieldNames.newsletter` is the base name (`newsletter-subscribe`) — append
> `[]` yourself, as above, since it submits as an array of checked list IDs.

## Newsletter checkboxes & the routing hook

> **Lite** offers a single plain opt-in checkbox. Multi-list opt-in and `triggersHook`
> routing modifiers are **Pro** — the cap is applied server-side, so `render()`,
> `data()` and the submit path all agree.

The **newsletter-list catalog** (Downtoll CP section) is just a list of
opt-in checkboxes. Each row is `{ label, listId, triggersHook }`:

- **List ID** — the identifier handed to your integration. It may be a literal
  (e.g. a CampaignMonitor list ID) **or an `$ENV_VAR` reference** — resolve it
  server-side in your listener (`craft\helpers\App::parseEnv()`), so the real id
  never ships in page HTML.
- **Triggers hook** — marks the box as a routing **modifier** rather than a plain
  opt-in. A checked hook box is surfaced on the submission event
  (`$e->triggeredHooks`) so a listener can classify the lead or re-route, but on
  its own it does not count as a newsletter opt-in. This is the "a checkbox that
  changes the routing" extension point — one flag, interpreted entirely by your
  listener, with zero plugin config knobs.

A resource offers a subset of the catalog (the field's "Newsletter lists"
checkbox group); the chosen list IDs + which are hook boxes are baked into the
signed token, so the server trusts them on submit.

## Custom integrations (the extension point) <sup>PRO</sup>

> `EVENT_AFTER_SUBMISSION` does not fire on Lite, so no listener runs. This is the
> "integrate with anything" capability, and it is what Pro sells.

Subscribe to the submission event from your own module/plugin:

```php
use yii\base\Event;
use craft\helpers\App;
use dgaidula\downtoll\services\Submissions;
use dgaidula\downtoll\events\SubmissionEvent;

Event::on(Submissions::class, Submissions::EVENT_AFTER_SUBMISSION, function (SubmissionEvent $e) {
    // $e->fields          Title-Case payload; $e->fields['Newsletter Lists'] = checked list IDs
    // $e->triggeredHooks  checked list IDs flagged `triggersHook` (the modifier boxes)
    // $e->downloadName    the gated file's name, resolved server-side from the signed asset id
    // $e->config          the per-resource ResourceConfig; $e->rawPayload the raw POST
    // Set $e->isValid = false to hard-gate (block the download) on a hard failure.

    $checked    = (array) ($e->fields['Newsletter Lists'] ?? []);
    $plainOptIn = array_values(array_diff($checked, $e->triggeredHooks)); // non-hook boxes

    // Example: only subscribe to your ESP if a plain opt-in box was checked; a hook
    // box on its own just classifies. Resolve $ENV list IDs at the API call.
    if ($plainOptIn !== []) {
        foreach ($checked as $listId) {
            myEspSubscribe(App::parseEnv($listId), $e->fields['Email']);
        }
    }
    $classification = $e->triggeredHooks !== [] ? 'Professional' : 'General';
    // …push $classification + $e->downloadName to your CRM…
});
```

### Worked examples

See [`examples/`](examples/) for reference listeners:

- [`examples/README.md`](examples/README.md) — why there is no "CRM settings" tab,
  the minimal listener, and the full event contract.
- [`examples/salesforce-campaign-monitor/`](examples/salesforce-campaign-monitor/) —
  a complete production-shaped integration: CRM Contact upsert, one timestamped
  engagement record per download, opt-in vs drip-list routing, env-only config, and
  the gotchas (restricted picklists, lookup-id validation, error-body capture,
  never letting an integration failure break the visitor's download).

The shipped `WebhookIntegration` is itself a listener on this same event — the
simplest one to read.

## Front end

**The plugin ships no front-end JS.** The consuming site supplies the form's
front-end controller — toggling the district lookup, driving the typeahead against
your configured endpoint, generating the reCAPTCHA token, and handling the
swap/reload success states.

A typical setup builds a site component from its own form primitives and drives it
with `data()`, which is why `data()` returns the endpoint, site key, field names and
signed token rather than any markup. `render()` exists for a quick start and as a
readable reference for what your own markup needs to post.
